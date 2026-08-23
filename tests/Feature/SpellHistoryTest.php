<?php

namespace Tests\Feature;

use App\Services\SpellHistory\SpellCanonicalizer;
use App\Services\SpellHistory\SpellHistoryArtifact;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class SpellHistoryTest extends TestCase
{
    private string $artifactRoot;

    private string $datasetKey;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('everquest.spell_history.enable', true);
        config()->set('everquest.spell_history.baseline_date');
        config()->set('everquest.spell_history.page_size', 25);
        config()->set('everquest.spell_history.max_page', 500);
        config()->set('everquest.current_expansion', 9);
        config()->set('everquest.expansions.9', 'Dragons of Norrath');

        $this->artifactRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'modern-allaclone-spell-history-tests-'.bin2hex(random_bytes(8));
        $this->datasetKey = str_repeat('a', 64);

        mkdir($this->artifactRoot, 0755, true);
        config()->set('everquest.spell_history.artifact_path', $this->artifactRoot);
        $this->writeArtifactFixture();
    }

    protected function tearDown(): void
    {
        $this->removeArtifactFixture();

        parent::tearDown();
    }

    public function test_disabled_history_is_not_discoverable(): void
    {
        config()->set('everquest.spell_history.enable', false);

        $this->get('/spells/3467/history')
            ->assertNotFound()
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_history_accepts_only_numeric_spell_ids(): void
    {
        $this->get('/spells/virtue/history')->assertNotFound();
    }

    public function test_history_renders_archive_context_and_a_resolved_cutoff(): void
    {
        config()->set('everquest.spell_history.baseline_date', '2005-02-15');

        $response = $this->get('/spells/3467/history');

        $response->assertOk()
            ->assertSeeText('Virtue History')
            ->assertSeeText('Current server progression')
            ->assertSeeText('Dragons of Norrath')
            ->assertSeeText('Resolved capture')
            ->assertSeeText('Configured cutoff: February 15, 2005')
            ->assertSeeText('Resolved capture: February 10, 2005 at 11:16:59 AM')
            ->assertSeeText('Using the latest available capture at or before the configured cutoff')
            ->assertSeeText('A date-only cutoff')
            ->assertSeeText('through the end of that day')
            ->assertSeeText('comparable semantic values first observed between captures')
            ->assertSeeText('first appearance in a changed export schema')
            ->assertSeeText('without timezone conversion')
            ->assertSeeText('not necessarily the exact patch time')
            ->assertSeeText('can differ from custom server data')
            ->assertSeeText('Cast on you message')
            ->assertSeeText('Current HP (0)')
            ->assertSeeText('AC (1)')
            ->assertSeeText('Technical changes (1)')
            ->assertSeeText('Spell present at resolved capture')
            ->assertSeeText('State at spell-data cutoff');

        $response->assertHeaderMissing('Set-Cookie');
    }

    public function test_history_route_executes_no_database_queries(): void
    {
        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->get('/spells/3467/history')->assertOk();

        $this->assertSame(0, $queryCount);
    }

    public function test_history_escapes_archived_values(): void
    {
        $this->writeArtifactFixture('<script>alert("history")</script>');

        $response = $this->get('/spells/3467/history');

        $response->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;history&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("history")</script>', false);
    }

    public function test_history_pagination_is_bounded(): void
    {
        config()->set('everquest.spell_history.page_size', 1);
        config()->set('everquest.spell_history.max_page', 2);

        $this->get('/spells/3467/history?page=2')
            ->assertOk()
            ->assertSeeText('Page 2')
            ->assertSeeText('First observed');

        $this->get('/spells/3467/history?page=3')->assertNotFound();
    }

    public function test_history_rejects_noncanonical_or_unrecognized_query_strings(): void
    {
        config()->set('everquest.spell_history.page_size', 1);

        $this->get('/spells/3467/history?page=2')->assertOk();

        foreach ([
            'page=2junk',
            'page=02',
            'page=+2',
            'page=0',
            'page[]=2',
            'page=2&tracking=1',
            'tracking=1',
            'page=2&page=2',
        ] as $queryString) {
            $this->get('/spells/3467/history?'.$queryString)->assertNotFound();
        }
    }

    public function test_history_pagination_links_use_only_canonical_route_parameters(): void
    {
        config()->set('everquest.spell_history.page_size', 1);
        config()->set('everquest.spell_history.baseline_date', '2005-02-15');

        $firstPage = $this->get('/spells/3467/history');
        $secondPageUrl = route('spells.history', ['spell' => 3467, 'page' => 2]);
        $firstPage->assertOk();
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote($secondPageUrl, '/').'"\s+rel="next"[^>]*>Older captures<\/a>/',
            $firstPage->getContent(),
        );

        $secondPage = $this->get('/spells/3467/history?page=2');
        $firstPageUrl = route('spells.history', ['spell' => 3467]);
        $secondPage->assertOk()->assertDontSee('?page=1', false);
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote($firstPageUrl, '/').'"\s+rel="prev"[^>]*>Newer captures<\/a>/',
            $secondPage->getContent(),
        );
    }

    public function test_explicit_timestamp_can_resolve_an_exact_capture(): void
    {
        config()->set('everquest.spell_history.baseline_date', '2005-02-10T11:16:59');

        $this->get('/spells/3467/history')
            ->assertOk()
            ->assertSeeText('Exact configured capture')
            ->assertSeeText('an explicit timestamp resolves through that')
            ->assertSeeText('exact second');
    }

    public function test_history_responses_have_a_dataset_etag_and_honor_revalidation(): void
    {
        config()->set('everquest.spell_history.baseline_date', '2005-02-15');

        $response = $this->get('/spells/3467/history');
        $etag = $response->headers->get('ETag');

        $response->assertOk();
        $this->assertNotNull($etag);
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $response->assertHeaderMissing('Set-Cookie');

        $this->withHeader('If-None-Match', $etag)
            ->get('/spells/3467/history')
            ->assertNotModified()
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_missing_archived_spell_returns_not_found(): void
    {
        $this->get('/spells/999999/history')->assertNotFound();
    }

    public function test_enabled_history_with_no_active_artifact_returns_not_found(): void
    {
        unlink($this->artifactRoot.DIRECTORY_SEPARATOR.'CURRENT');

        $this->get('/spells/3467/history')->assertNotFound();
    }

    public function test_unconfigured_cutoff_is_explicit(): void
    {
        $this->get('/spells/3467/history')
            ->assertOk()
            ->assertSeeText('No spell-data cutoff is configured')
            ->assertDontSeeText('State at spell-data cutoff');
    }

    public function test_cutoff_before_the_archive_has_no_resolved_capture_or_highlight(): void
    {
        config()->set('everquest.spell_history.baseline_date', '2000-01-01');

        $this->get('/spells/3467/history')
            ->assertOk()
            ->assertSeeText('No available capture at or before this cutoff')
            ->assertSeeText('predates the first available Lucy capture')
            ->assertDontSee('Resolved capture:</strong>', false)
            ->assertDontSeeText('State at spell-data cutoff');
    }

    public function test_cutoff_before_first_observation_shows_not_yet_observed_without_a_selection(): void
    {
        config()->set('everquest.spell_history.page_size', 1);
        config()->set('everquest.spell_history.baseline_date', '2001-01-01');

        $this->get('/spells/3467/history')
            ->assertOk()
            ->assertSeeText('Spell not yet observed at this cutoff')
            ->assertSeeText('This spell first appears later in the archive')
            ->assertDontSeeText('State at spell-data cutoff')
            ->assertDontSeeText('Jump to highlighted capture');
    }

    public function test_confirmed_absence_shows_not_observed_without_a_selection(): void
    {
        $this->writeArtifactFixture(presenceScenario: 'not_observed');
        config()->set('everquest.spell_history.page_size', 1);
        config()->set('everquest.spell_history.baseline_date', '2005-02-10');

        $this->get('/spells/3467/history')
            ->assertOk()
            ->assertSeeText('Spell not observed at resolved capture')
            ->assertSeeText('Lucy omitted this spell from consecutive captures at the cutoff')
            ->assertDontSeeText('State at spell-data cutoff')
            ->assertDontSeeText('Jump to highlighted capture');
    }

    public function test_single_capture_gap_shows_uncertainty_without_a_selection(): void
    {
        $this->writeArtifactFixture(presenceScenario: 'uncertain_single_capture_gap');
        config()->set('everquest.spell_history.page_size', 1);
        config()->set('everquest.spell_history.baseline_date', '2005-02-10');

        $this->get('/spells/3467/history')
            ->assertOk()
            ->assertSeeText('Availability uncertain at this cutoff')
            ->assertSeeText('This spell is missing from one Lucy capture')
            ->assertDontSeeText('State at spell-data cutoff')
            ->assertDontSeeText('Jump to highlighted capture');
    }

    public function test_invalid_cutoff_fails_closed_and_is_distinct_from_unconfigured(): void
    {
        config()->set('everquest.spell_history.baseline_date', 'not-a-date');

        $this->get('/spells/3467/history')
            ->assertOk()
            ->assertSeeText('Invalid configured cutoff')
            ->assertSeeText('Use YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS')
            ->assertSeeText('No revision is highlighted while this setting is invalid')
            ->assertDontSeeText('No spell-data cutoff is configured')
            ->assertDontSeeText('State at spell-data cutoff');
    }

    public function test_invalid_calendar_cutoff_is_not_normalized_for_display(): void
    {
        config()->set('everquest.spell_history.baseline_date', '2025-02-30');

        $this->get('/spells/3467/history')
            ->assertOk()
            ->assertSeeText('Invalid configured cutoff')
            ->assertSeeText('Configured cutoff: 2025-02-30')
            ->assertDontSeeText('March 2, 2025');
    }

    private function writeArtifactFixture(
        string $castOnYou = 'You are filled with virtue.',
        ?string $presenceScenario = null,
    ): void {
        $datasetRoot = $this->artifactRoot.DIRECTORY_SEPARATOR.'datasets'.DIRECTORY_SEPARATOR.$this->datasetKey;
        if (! is_dir($datasetRoot)) {
            mkdir($datasetRoot, 0755, true);
        }

        $snapshots = [
            [
                'sequence' => 1,
                'key' => 'spelldata_Live_2001-01-01_00_00_00',
                'observed_at' => '2001-01-01T00:00:00',
                'previous_snapshot' => null,
            ],
            [
                'sequence' => 2,
                'key' => 'spelldata_Live_2002-10-17_11_10_02',
                'observed_at' => '2002-10-17T11:10:02',
                'previous_snapshot' => 'spelldata_Live_2001-01-01_00_00_00',
            ],
            [
                'sequence' => 3,
                'key' => 'spelldata_Live_2005-02-10_11_16_59',
                'observed_at' => '2005-02-10T11:16:59',
                'previous_snapshot' => 'spelldata_Live_2002-10-17_11_10_02',
            ],
            [
                'sequence' => 4,
                'key' => 'spelldata_Live_2024-04-09_01_03_00',
                'observed_at' => '2024-04-09T01:03:00',
                'previous_snapshot' => 'spelldata_Live_2005-02-10_11_16_59',
            ],
        ];
        $snapshots = array_map(static fn (array $snapshot): array => [
            ...$snapshot,
            'source_physical_line_count' => 2,
            'health_status' => 'healthy',
            'trusted_for_absence_confirmation' => true,
        ], $snapshots);

        $manifest = [
            'schema' => SpellHistoryArtifact::SCHEMA,
            'artifact_type' => 'manifest',
            'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
            'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
            'dataset' => $this->datasetKey,
            'generated_at' => '2026-08-22T00:00:00Z',
            'snapshots' => $snapshots,
            'stats' => [
                'snapshot_count' => count($snapshots),
                'spell_count' => 1,
                'revision_count' => 2,
            ],
        ];

        $spell = [
            'schema' => SpellHistoryArtifact::SCHEMA,
            'artifact_type' => 'spell',
            'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
            'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
            'dataset' => $this->datasetKey,
            'spell_id' => 3467,
            'latest_name' => 'Virtue',
            'first_observed_at' => '2002-10-17T11:10:02',
            'last_observed_at' => '2024-04-09T01:03:00',
            'present_in_latest_snapshot' => true,
            'revision_count' => 2,
            'revisions' => [
                [
                    'snapshot' => 'spelldata_Live_2002-10-17_11_10_02',
                    'observed_at' => '2002-10-17T11:10:02',
                    'previous_snapshot' => 'spelldata_Live_2001-01-01_00_00_00',
                    'type' => 'first_observed',
                    'name' => 'Virtue',
                    'groups' => [],
                ],
                [
                    'snapshot' => 'spelldata_Live_2024-04-09_01_03_00',
                    'observed_at' => '2024-04-09T01:03:00',
                    'previous_snapshot' => 'spelldata_Live_2005-02-10_11_16_59',
                    'type' => 'changed',
                    'name' => 'Virtue',
                    'groups' => [
                        [
                            'key' => 'messages',
                            'section' => 'messages',
                            'label' => 'Messages',
                            'changes' => [[
                                'field' => 'cast_on_you',
                                'label' => 'Cast on you message',
                                'old' => 'Your will staggers and sways.',
                                'new' => $castOnYou,
                            ]],
                        ],
                        [
                            'key' => 'advanced',
                            'section' => 'advanced',
                            'label' => 'Advanced',
                            'changes' => [[
                                'field' => 'unknown3',
                                'label' => 'Unknown 3',
                                'old' => 0,
                                'new' => 100020070,
                            ]],
                        ],
                        [
                            'key' => 'effect_1',
                            'section' => 'effects',
                            'label' => 'Slot 1',
                            'changes' => [[
                                'field' => 'effects.1.attribute',
                                'label' => 'Effect',
                                'old' => 0,
                                'new' => 1,
                            ]],
                        ],
                    ],
                ],
            ],
        ];

        if ($presenceScenario === 'uncertain_single_capture_gap') {
            $spell['absence_ranges'] = [[
                'first_snapshot' => 'spelldata_Live_2005-02-10_11_16_59',
                'last_snapshot' => 'spelldata_Live_2005-02-10_11_16_59',
                'first_observed_at' => '2005-02-10T11:16:59',
                'last_observed_at' => '2005-02-10T11:16:59',
                'captures' => 1,
                'trusted_captures' => 1,
                'confirmed' => false,
            ]];
        } elseif ($presenceScenario === 'not_observed') {
            $spell['last_observed_at'] = '2002-10-17T11:10:02';
            $spell['present_in_latest_snapshot'] = false;
            $spell['latest_presence_status'] = 'not_observed';
            $spell['absence_ranges'] = [[
                'first_snapshot' => 'spelldata_Live_2005-02-10_11_16_59',
                'last_snapshot' => 'spelldata_Live_2024-04-09_01_03_00',
                'first_observed_at' => '2005-02-10T11:16:59',
                'last_observed_at' => '2024-04-09T01:03:00',
                'captures' => 2,
                'trusted_captures' => 2,
                'confirmed' => true,
            ]];
            $spell['revisions'][1] = [
                'snapshot' => 'spelldata_Live_2024-04-09_01_03_00',
                'observed_at' => '2024-04-09T01:03:00',
                'previous_snapshot' => 'spelldata_Live_2005-02-10_11_16_59',
                'type' => 'presence_missing',
                'name' => 'Virtue',
                'groups' => [],
                'first_missing_snapshot' => 'spelldata_Live_2005-02-10_11_16_59',
            ];
        } elseif ($presenceScenario !== null) {
            throw new \InvalidArgumentException("Unknown presence fixture: {$presenceScenario}");
        }

        file_put_contents(
            $this->artifactRoot.DIRECTORY_SEPARATOR.'CURRENT',
            $this->datasetKey."\n",
        );
        $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents(
            $datasetRoot.DIRECTORY_SEPARATOR.'manifest.json',
            $manifestJson,
        );
        file_put_contents(
            $datasetRoot.DIRECTORY_SEPARATOR.'COMPLETE.json',
            json_encode([
                'schema' => SpellHistoryArtifact::SCHEMA,
                'artifact_type' => 'completion',
                'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
                'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
                'dataset' => $this->datasetKey,
                'manifest_sha256' => hash('sha256', $manifestJson),
                'manifest_bytes' => strlen($manifestJson),
                'spell_count' => $manifest['stats']['spell_count'],
                'revision_count' => $manifest['stats']['revision_count'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        $spellPath = $datasetRoot.DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            SpellHistoryArtifact::spellRelativePath(3467),
        );
        if (! is_dir(dirname($spellPath))) {
            mkdir(dirname($spellPath), 0755, true);
        }
        file_put_contents(
            $spellPath,
            json_encode($spell, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    private function removeArtifactFixture(): void
    {
        $expectedPrefix = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'modern-allaclone-spell-history-tests-';
        $resolvedRoot = realpath($this->artifactRoot);

        if ($resolvedRoot === false || ! str_starts_with($resolvedRoot, $expectedPrefix)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($resolvedRoot);
    }
}

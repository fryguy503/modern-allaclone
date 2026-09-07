<?php

namespace Tests\Feature;

use App\Services\ItemHistory\ItemHistoryArtifact;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItemHistoryTest extends TestCase
{
    private string $artifactRoot;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('i', 32)));
        config()->set('everquest.item_history.enable', true);
        config()->set('everquest.item_history.page_size', 2);
        config()->set('everquest.item_history.max_page', 500);

        $this->artifactRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'modern-allaclone-item-history-tests-'.bin2hex(random_bytes(8));
        mkdir($this->artifactRoot, 0755, true);
        config()->set('everquest.item_history.artifact_path', $this->artifactRoot);
        $this->writeArtifact();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->artifactRoot);

        parent::tearDown();
    }

    public function test_disabled_or_missing_history_is_not_discoverable(): void
    {
        config()->set('everquest.item_history.enable', false);
        $this->get('/items/20542/history')
            ->assertNotFound()
            ->assertHeaderMissing('Set-Cookie');

        config()->set('everquest.item_history.enable', true);
        $this->get('/items/999999/history')
            ->assertNotFound()
            ->assertHeaderMissing('Set-Cookie');
        $this->get('/items/not-an-item/history')->assertNotFound();
    }

    public function test_card_history_renders_archive_context_and_escapes_captured_values(): void
    {
        $response = $this->get('/items/20542/history?view=cards');

        $response->assertOk()
            ->assertSeeText('Ceremonial Iksar Chestplate History')
            ->assertSeeText('Lucy item archive')
            ->assertSeeText('Captured revisions')
            ->assertSeeText('Cards')
            ->assertSeeText('Table')
            ->assertSeeText('Entry 2022')
            ->assertSeeText('Live')
            ->assertSeeText('Captured item snapshot')
            ->assertSee('&lt;script&gt;alert(&quot;item-history&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("item-history")</script>', false)
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_reversible_history_is_clearly_labeled_and_supports_optional_direct_details(): void
    {
        $this->writeReversibleArtifact();

        $cards = $this->get('/items/20542/history?view=cards');
        $cards->assertOk()
            ->assertSeeText('Reconstructed field history')
            ->assertSeeText('Recorded revisions')
            ->assertSeeText('1 directly captured detail')
            ->assertSeeText('Directly captured Lucy detail snapshot')
            ->assertSeeText('No Lucy-rendered detail snapshot was fetched for this revision.')
            ->assertDontSeeText('Captured item snapshot')
            ->assertSee('&lt;script&gt;alert(&quot;item-history&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("item-history")</script>', false)
            ->assertHeaderMissing('Set-Cookie');

        $this->get('/items/20542/history?view=table')
            ->assertOk()
            ->assertSeeText('Lore text added')
            ->assertSeeText('AC changed from 10 to 12')
            ->assertDontSee('<script>alert("item-history")</script>', false)
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_table_mode_is_compact_and_pagination_preserves_the_selected_view(): void
    {
        $firstPage = $this->get('/items/20542/history?view=table');
        $firstPage->assertOk()
            ->assertSee('Lucy-style compact item revision table')
            ->assertSeeText('Recorded')
            ->assertSeeText('Source')
            ->assertSeeText('Revision / entry')
            ->assertSeeText('Operation')
            ->assertSeeText('Field')
            ->assertSeeText('Before')
            ->assertSeeText('After')
            ->assertSeeText('Description')
            ->assertSeeText('Lore text added')
            ->assertSeeText('AC changed from 10 to 12')
            ->assertSeeText('Entry 2022')
            ->assertSee('?view=table&amp;page=2', false);

        $secondPage = $this->get('/items/20542/history?view=table&page=2');
        $secondPage->assertOk()
            ->assertSeeText('Initial entry')
            ->assertSee('?view=table', false)
            ->assertDontSee('?view=table&amp;page=1', false);
    }

    public function test_history_rejects_noncanonical_or_unrecognized_query_strings(): void
    {
        foreach ([
            'view=grid',
            'page=1',
            'view=cards&page=1',
            'page=2&view=table',
            'view=table&extra=1',
            'view=table&view=cards',
        ] as $queryString) {
            $this->get('/items/20542/history?'.$queryString)->assertNotFound();
        }
    }

    public function test_history_route_executes_no_database_queries(): void
    {
        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->get('/items/20542/history?view=table')->assertOk();

        $this->assertSame(0, $queryCount);
    }

    public function test_history_responses_have_an_etag_and_honor_revalidation(): void
    {
        $response = $this->get('/items/20542/history');
        $etag = $response->headers->get('ETag');

        $response->assertOk();
        $this->assertNotNull($etag);
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $response->assertHeaderMissing('Set-Cookie');

        $this->withHeader('If-None-Match', $etag)
            ->get('/items/20542/history')
            ->assertNotModified()
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_large_valid_revisions_are_split_before_the_response_render_budget_is_exceeded(): void
    {
        config()->set('everquest.item_history.page_size', 100);
        $path = $this->artifactRoot.'/'.ItemHistoryArtifact::itemRelativePath(20_542);
        $artifact = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $largeSnapshot = array_fill(0, 25, str_repeat('&', 8_000));
        foreach ($artifact['revisions'] as &$revision) {
            $revision['detail']['snapshot_lines'] = $largeSnapshot;
        }
        unset($revision);
        file_put_contents($path, json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $response = $this->get('/items/20542/history?view=cards');

        $response->assertOk()
            ->assertSeeText('Entry 2022')
            ->assertDontSeeText('Entry 2020')
            ->assertSee('?view=cards&amp;page=2', false);
        $this->assertLessThan(2_097_152, strlen((string) $response->getContent()));
    }

    private function writeArtifact(): void
    {
        $artifact = [
            'schema' => ItemHistoryArtifact::SCHEMA,
            'artifact_type' => 'item',
            'format_version' => ItemHistoryArtifact::FORMAT_VERSION,
            'item_id' => 20_542,
            'generated_at' => '2026-08-23T03:00:00Z',
            'latest_name' => 'Ceremonial Iksar Chestplate',
            'latest_icon' => 512,
            'first_observed_at' => '2019-01-01T12:30:00',
            'last_observed_at' => '2022-03-03T14:00:00',
            'revision_count' => 3,
            'sources' => ['Live', 'Test'],
            'complete' => true,
            'gaps' => [],
            'revisions' => [
                [
                    'entry_id' => 2_019,
                    'source' => 'Live',
                    'observed_at' => '2019-01-01T12:30:00',
                    'observed_precision' => 'minute',
                    'type' => 'initial',
                    'changes' => [[
                        'operation' => 'initial',
                        'field' => null,
                        'before' => null,
                        'after' => null,
                        'display' => 'Initial entry',
                    ]],
                    'detail' => [
                        'name' => 'Ceremonial Iksar Chestplate',
                        'icon' => 512,
                        'snapshot_lines' => ['Initial item snapshot'],
                    ],
                    'capture_sha256' => str_repeat('a', 64),
                ],
                [
                    'entry_id' => 2_020,
                    'source' => 'Live',
                    'observed_at' => '2020-02-02T13:45:10',
                    'observed_precision' => 'second',
                    'type' => 'changed',
                    'changes' => [[
                        'operation' => 'changed',
                        'field' => 'ac',
                        'before' => '10',
                        'after' => '12',
                        'display' => 'AC changed from 10 to 12',
                    ]],
                    'detail' => [
                        'name' => 'Ceremonial Iksar Chestplate',
                        'icon' => 512,
                        'snapshot_lines' => ['AC: 12'],
                    ],
                    'capture_sha256' => str_repeat('b', 64),
                ],
                [
                    'entry_id' => 2_022,
                    'source' => 'Test',
                    'observed_at' => '2022-03-03T14:00:00',
                    'observed_precision' => 'minute',
                    'type' => 'changed',
                    'changes' => [[
                        'operation' => 'added',
                        'field' => 'lore',
                        'before' => null,
                        'after' => '<script>alert("item-history")</script>',
                        'display' => 'Lore text added',
                    ]],
                    'detail' => [
                        'name' => 'Ceremonial Iksar Chestplate',
                        'icon' => 512,
                        'snapshot_lines' => [
                            'Lore text snapshot',
                            '<script>alert("item-history")</script>',
                        ],
                    ],
                    'capture_sha256' => str_repeat('c', 64),
                ],
            ],
        ];

        $path = $this->artifactRoot.'/'.ItemHistoryArtifact::itemRelativePath(20_542);
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function writeReversibleArtifact(): void
    {
        $path = $this->artifactRoot.'/'.ItemHistoryArtifact::itemRelativePath(20_542);
        $artifact = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $historyHash = str_repeat('d', 64);
        $rawHash = str_repeat('e', 64);

        $artifact['format_version'] = ItemHistoryArtifact::REVERSIBLE_DELTA_FORMAT_VERSION;
        $artifact['parser_format_version'] = ItemHistoryArtifact::REVERSIBLE_DELTA_PARSER_FORMAT_VERSION;
        $artifact['capture_strategy'] = ItemHistoryArtifact::REVERSIBLE_DELTA_CAPTURE_STRATEGY;
        $artifact['coverage'] = [
            'history_rows' => 'captured',
            'current_raw' => 'captured',
            'historical_state' => 'reconstructed',
            'rendered_details' => 'partial',
            'direct_detail_count' => 1,
        ];
        $artifact['evidence'] = [
            'history_capture_sha256s' => [$historyHash],
            'current_raw_capture_sha256' => $rawHash,
            'current_raw_source' => 'Live',
        ];
        $artifact['reconstruction'] = [
            'algorithm' => 'lucy-reversible-delta',
            'version' => 1,
            'derivation_sha256' => str_repeat('f', 64),
            'value_encoding' => 'lucy-history-display-v1',
            'sources' => [
                'Live' => [
                    'status' => 'chain-verified-anchored',
                    'revision_count' => 2,
                    'change_count' => 2,
                    'tracked_field_count' => 1,
                    'continuity_checks' => 0,
                ],
                'Test' => [
                    'status' => 'chain-verified-unanchored',
                    'revision_count' => 1,
                    'change_count' => 1,
                    'tracked_field_count' => 1,
                    'continuity_checks' => 0,
                ],
            ],
        ];
        $artifact['current_raw'] = [
            'Live' => [
                'source' => 'Live',
                'fields' => [
                    'id' => '20542',
                    'name' => 'Ceremonial Iksar Chestplate',
                    'ac' => '12',
                ],
                'capture_sha256' => $rawHash,
            ],
        ];
        foreach ($artifact['revisions'] as &$revision) {
            $revision['history_capture_sha256s'] = [$historyHash];
        }
        unset($revision);
        unset(
            $artifact['revisions'][0]['detail'],
            $artifact['revisions'][0]['capture_sha256'],
            $artifact['revisions'][1]['detail'],
            $artifact['revisions'][1]['capture_sha256'],
        );

        file_put_contents($path, json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
                }
            }
        }
        @rmdir($path);
    }
}

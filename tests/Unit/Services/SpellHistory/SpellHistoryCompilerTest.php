<?php

namespace Tests\Unit\Services\SpellHistory;

use App\Services\SpellHistory\SnapshotLocator;
use App\Services\SpellHistory\SpellCanonicalizer;
use App\Services\SpellHistory\SpellHistoryArtifact;
use App\Services\SpellHistory\SpellHistoryCompiler;
use App\Services\SpellHistory\SpellHistoryRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class SpellHistoryCompilerTest extends TestCase
{
    private string $temporaryDirectory;

    private string $sourceDirectory;

    private string $artifactDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().'/spell-history-compiler-'.bin2hex(random_bytes(8));
        $this->sourceDirectory = $this->temporaryDirectory.'/source';
        $this->artifactDirectory = $this->temporaryDirectory.'/artifacts';
        mkdir($this->sourceDirectory, 0755, true);
        mkdir($this->artifactDirectory, 0755, true);
        $this->writeFixtureSnapshots();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_it_compiles_semantic_revisions_and_activates_an_immutable_dataset(): void
    {
        $result = $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);

        $this->assertFalse($result['reused']);
        $this->assertSame(4, $result['snapshots']);
        $this->assertSame(4, $result['spells']);
        $this->assertMatchesRegularExpression(SpellHistoryArtifact::DATASET_KEY_PATTERN, $result['dataset']);
        $this->assertSame($result['dataset'], trim((string) file_get_contents($this->artifactDirectory.'/CURRENT')));
        $this->assertFileExists($result['path'].'/COMPLETE.json');
        $this->assertSame(2, substr_count(SpellHistoryArtifact::spellRelativePath(1), '/'));

        $repository = new SpellHistoryRepository($this->artifactDirectory);
        $virtue = $repository->spell(1);

        $this->assertNotNull($virtue);
        $this->assertSame(['first_observed', 'changed', 'changed'], array_column($virtue['revisions'], 'type'));
        $this->assertSame(
            ['classes', 'effect.1'],
            array_column($virtue['revisions'][1]['groups'], 'key'),
            'A newly exported unknown3 field must not masquerade as a change.',
        );
        $this->assertSame(
            'spelldata_Live_2002-01-01_10_00_00',
            $virtue['revisions'][1]['groups'][0]['changes'][0]['compared_from_snapshot'],
        );
        $this->assertSame('unknown3', $virtue['revisions'][2]['groups'][0]['changes'][0]['field']);

        $gone = $repository->spell(2);
        $this->assertSame(
            ['first_observed', 'presence_missing', 'presence_restored'],
            array_column($gone['revisions'], 'type'),
        );
        $this->assertSame('classes', $gone['revisions'][2]['groups'][0]['key']);

        $transient = $repository->spell(4);
        $this->assertSame(['first_observed'], array_column($transient['revisions'], 'type'));
        $this->assertFalse($transient['absence_ranges'][0]['confirmed']);

        $new = $repository->spell(3);
        $this->assertSame(['first_observed'], array_column($new['revisions'], 'type'));
        $this->assertTrue($repository->hasHistory(3));
        $this->assertFalse($repository->hasHistory(999));
    }

    public function test_repository_resolves_naive_dates_and_presents_a_paginated_baseline(): void
    {
        $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        $repository = new SpellHistoryRepository($this->artifactDirectory);

        $this->assertNull($repository->resolveSnapshotAtOrBefore('2001-12-31'));
        $this->assertSame(
            'spelldata_Live_2002-01-02_12_30_00',
            $repository->resolveSnapshotAtOrBefore('2002-01-02')['key'],
        );
        $this->assertSame(
            'spelldata_Live_2002-01-01_10_00_00',
            $repository->resolveSnapshotAtOrBefore('2002-01-02T12:29:59')['key'],
        );

        $history = $repository->forSpellAtDate(1, '2002-01-02', 1, 2);
        $this->assertSame(2, count($history['revisions']));
        $this->assertSame('spelldata_Live_2002-01-03_09_00_00', $history['revisions'][0]['snapshot']);
        $this->assertFalse($history['revisions'][0]['is_server_baseline']);
        $this->assertTrue($history['revisions'][1]['is_server_baseline']);
        $this->assertSame(2, $history['pagination']['last_page']);
        $this->assertTrue($history['pagination']['has_more']);
        $this->assertSame('spelldata_Live_2002-01-02_12_30_00', $history['baseline']['snapshot']['key']);
        $this->assertSame(1, $history['baseline']['page']);

        $gone = $repository->forSpellAtDate(2, '2002-01-03', 1, 10);
        $missing = array_values(array_filter(
            $gone['revisions'],
            static fn (array $revision): bool => $revision['type'] === 'presence_missing',
        ))[0];
        $this->assertSame('availability', $missing['groups'][0]['key']);
        $this->assertSame('Not observed', $missing['groups'][0]['changes'][0]['new']);
        $this->assertFalse($gone['baseline']['spell_present']);

        $transient = $repository->forSpellAtDate(4, '2002-01-02', 1, 10);
        $this->assertNull($transient['baseline']['spell_present']);
        $this->assertSame('uncertain_single_capture_gap', $transient['baseline']['presence_status']);
    }

    public function test_rebuilding_identical_sources_reuses_the_immutable_dataset(): void
    {
        $first = $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        $second = $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);

        $this->assertSame($first['dataset'], $second['dataset']);
        $this->assertTrue($second['reused']);
        $this->assertSame(1, count(glob($this->artifactDirectory.'/datasets/*', GLOB_ONLYDIR)));
    }

    public function test_an_incomplete_existing_dataset_is_never_activated(): void
    {
        $first = $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        file_put_contents($first['path'].'/COMPLETE.json', '{}');

        $this->expectException(RuntimeException::class);
        $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
    }

    public function test_repository_rejects_an_active_dataset_without_a_completion_marker(): void
    {
        $result = $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        unlink($result['path'].'/COMPLETE.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('completion marker');
        (new SpellHistoryRepository($this->artifactDirectory))->hasHistory(1);
    }

    public function test_repository_rejects_tampered_completion_marker_integrity_fields(): void
    {
        $result = $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        $path = $result['path'].'/COMPLETE.json';
        $completion = json_decode(
            (string) file_get_contents($path),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        $tamperedValues = [
            'manifest_sha256' => str_repeat('0', 64),
            'manifest_bytes' => $completion['manifest_bytes'] + 1,
            'spell_count' => $completion['spell_count'] + 1,
            'revision_count' => $completion['revision_count'] + 1,
        ];

        foreach ($tamperedValues as $field => $value) {
            file_put_contents(
                $path,
                json_encode(
                    [...$completion, $field => $value],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
            );

            try {
                (new SpellHistoryRepository($this->artifactDirectory))->manifest();
                $this->fail("A completion marker with a tampered {$field} was accepted.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('completion marker', $exception->getMessage());
            }
        }
    }

    public function test_repository_revalidates_completion_after_the_active_dataset_changes(): void
    {
        $first = $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        $repository = new SpellHistoryRepository($this->artifactDirectory);
        $this->assertSame($first['dataset'], $repository->manifest()['dataset']);

        copy(
            $this->sourceDirectory.'/spelldata_Live_2002-01-04_09_00_00.txt',
            $this->sourceDirectory.'/spelldata_Live_2002-01-05_09_00_00.txt',
        );
        $second = $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        $this->assertNotSame($first['dataset'], $second['dataset']);
        unlink($second['path'].'/COMPLETE.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('completion marker');
        $repository->manifest();
    }

    public function test_repository_rejects_an_untrusted_current_pointer(): void
    {
        $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        file_put_contents($this->artifactDirectory.'/CURRENT', "../../outside\n");

        $this->expectException(RuntimeException::class);
        (new SpellHistoryRepository($this->artifactDirectory))->manifest();
    }

    public function test_repository_rejects_invalid_dates_and_negative_spell_ids(): void
    {
        $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        $repository = new SpellHistoryRepository($this->artifactDirectory);

        try {
            $repository->resolveSnapshotAtOrBefore('2002-01-02T12:30:00Z');
            $this->fail('A timezone-bearing timestamp should be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $repository->spell(-1);
    }

    public function test_rfc_csv_fields_with_commas_and_newlines_are_streamed_safely(): void
    {
        $separateRoot = $this->temporaryDirectory.'/rfc-source';
        $separateArtifacts = $this->temporaryDirectory.'/rfc-artifacts';
        mkdir($separateRoot);
        mkdir($separateArtifacts);
        $this->writeSnapshot(
            $separateRoot.'/spelldata_Live_2002-01-01_00_00_00.txt',
            ['id', 'name'],
            [['10', "Virtue, Greater\nRank"]],
        );

        $this->compiler()->compile($separateRoot, $separateArtifacts);
        $spell = (new SpellHistoryRepository($separateArtifacts))->spell(10);

        $this->assertSame("Virtue, Greater\nRank", $spell['latest_name']);
    }

    public function test_windows_1252_is_detected_once_and_converted_while_streaming(): void
    {
        $separateRoot = $this->temporaryDirectory.'/windows-source';
        $separateArtifacts = $this->temporaryDirectory.'/windows-artifacts';
        mkdir($separateRoot);
        mkdir($separateArtifacts);
        file_put_contents(
            $separateRoot.'/spelldata_Live_2002-01-01_00_00_00.txt',
            "id,name\r\n10,\"Virtue \x93Greater\x94\"\r\n",
        );

        $this->compiler()->compile($separateRoot, $separateArtifacts);
        $repository = new SpellHistoryRepository($separateArtifacts);

        $this->assertSame('Virtue “Greater”', $repository->spell(10)['latest_name']);
        $this->assertSame('windows-1252', $repository->manifest()['snapshots'][0]['encoding']);
    }

    public function test_malformed_row_width_is_rejected_instead_of_padded(): void
    {
        $separateRoot = $this->temporaryDirectory.'/malformed-source';
        $separateArtifacts = $this->temporaryDirectory.'/malformed-artifacts';
        mkdir($separateRoot);
        mkdir($separateArtifacts);
        file_put_contents(
            $separateRoot.'/spelldata_Live_2002-01-01_00_00_00.txt',
            "id,name,mana\n10,Virtue\n",
        );

        $this->expectException(RuntimeException::class);
        $this->compiler()->compile($separateRoot, $separateArtifacts);
    }

    public function test_two_anomalously_small_captures_cannot_confirm_mass_removals(): void
    {
        $separateRoot = $this->temporaryDirectory.'/health-source';
        $separateArtifacts = $this->temporaryDirectory.'/health-artifacts';
        mkdir($separateRoot);
        mkdir($separateArtifacts);
        $fullRows = array_map(
            static fn (int $id): array => [(string) $id, "Spell {$id}"],
            range(1, 2_500),
        );
        $smallRows = array_slice($fullRows, 0, 1_000);
        $recoveryRows = array_slice($fullRows, 0, 2_450);

        $this->writeSnapshot($separateRoot.'/spelldata_Live_2002-01-01_00_00_00.txt', ['id', 'name'], $fullRows);
        $this->writeSnapshot($separateRoot.'/spelldata_Live_2002-01-02_00_00_00.txt', ['id', 'name'], $smallRows);
        $this->writeSnapshot($separateRoot.'/spelldata_Live_2002-01-03_00_00_00.txt', ['id', 'name'], $smallRows);
        $this->writeSnapshot($separateRoot.'/spelldata_Live_2002-01-04_00_00_00.txt', ['id', 'name'], $recoveryRows);

        $this->compiler()->compile($separateRoot, $separateArtifacts);
        $repository = new SpellHistoryRepository($separateArtifacts);
        $manifest = $repository->manifest();
        $spell = $repository->spell(2_000);

        $this->assertSame(2, $manifest['stats']['untrusted_snapshot_count']);
        $this->assertFalse($manifest['snapshots'][1]['trusted_for_absence_confirmation']);
        $this->assertFalse($manifest['snapshots'][2]['trusted_for_absence_confirmation']);
        $this->assertTrue($manifest['snapshots'][3]['trusted_for_absence_confirmation']);
        $this->assertSame(0, $spell['absence_ranges'][0]['trusted_captures']);
        $this->assertFalse($spell['absence_ranges'][0]['confirmed']);
        $this->assertSame(['first_observed'], array_column($spell['revisions'], 'type'));
    }

    public function test_snapshot_health_uses_logical_rows_and_partial_recoveries_remain_untrusted(): void
    {
        $separateRoot = $this->temporaryDirectory.'/logical-health-source';
        $separateArtifacts = $this->temporaryDirectory.'/logical-health-artifacts';
        mkdir($separateRoot);
        mkdir($separateArtifacts);
        $fullRows = array_map(
            static fn (int $id): array => [(string) $id, "Spell {$id}"],
            range(1, 5_000),
        );
        $lowRows = array_map(
            static fn (int $id): array => [(string) $id, "Spell {$id}\nline 2\nline 3\nline 4\nline 5"],
            range(1, 1_000),
        );

        $this->writeSnapshot($separateRoot.'/spelldata_Live_2002-01-01_00_00_00.txt', ['id', 'name'], $fullRows);
        $this->writeSnapshot($separateRoot.'/spelldata_Live_2002-01-02_00_00_00.txt', ['id', 'name'], $lowRows);
        $this->writeSnapshot($separateRoot.'/spelldata_Live_2002-01-03_00_00_00.txt', ['id', 'name'], array_slice($fullRows, 0, 3_000));
        $this->writeSnapshot($separateRoot.'/spelldata_Live_2002-01-04_00_00_00.txt', ['id', 'name'], array_slice($fullRows, 0, 3_500));
        $this->writeSnapshot($separateRoot.'/spelldata_Live_2002-01-05_00_00_00.txt', ['id', 'name'], array_slice($fullRows, 0, 4_950));

        $this->compiler()->compile($separateRoot, $separateArtifacts);
        $repository = new SpellHistoryRepository($separateArtifacts);
        $manifest = $repository->manifest();
        $spell = $repository->spell(4_500);

        $this->assertSame(3, $manifest['stats']['untrusted_snapshot_count']);
        $this->assertSame(1_000, $manifest['snapshots'][1]['row_count']);
        $this->assertGreaterThanOrEqual(
            $manifest['snapshots'][0]['source_physical_line_count'],
            $manifest['snapshots'][1]['source_physical_line_count'],
        );
        $this->assertFalse($manifest['snapshots'][1]['trusted_for_absence_confirmation']);
        $this->assertFalse($manifest['snapshots'][2]['trusted_for_absence_confirmation']);
        $this->assertFalse($manifest['snapshots'][3]['trusted_for_absence_confirmation']);
        $this->assertTrue($manifest['snapshots'][4]['trusted_for_absence_confirmation']);
        $this->assertSame(0, $spell['absence_ranges'][0]['trusted_captures']);
        $this->assertFalse($spell['absence_ranges'][0]['confirmed']);
        $this->assertSame(['first_observed'], array_column($spell['revisions'], 'type'));
    }

    public function test_latest_name_and_icon_distinguish_schema_omission_from_explicit_blank(): void
    {
        $separateRoot = $this->temporaryDirectory.'/latest-metadata-source';
        $separateArtifacts = $this->temporaryDirectory.'/latest-metadata-artifacts';
        mkdir($separateRoot);
        mkdir($separateArtifacts);

        $this->writeSnapshot(
            $separateRoot.'/spelldata_Live_2002-01-01_00_00_00.txt',
            ['id', 'name', 'spellicon'],
            [['10', 'Virtue', '132']],
        );
        $this->writeSnapshot(
            $separateRoot.'/spelldata_Live_2002-01-02_00_00_00.txt',
            ['id', 'mana'],
            [['10', '100']],
        );

        $this->compiler()->compile($separateRoot, $separateArtifacts);
        $retained = (new SpellHistoryRepository($separateArtifacts))->spell(10);
        $this->assertSame('Virtue', $retained['latest_name']);
        $this->assertSame(132, $retained['latest_icon']);

        $this->writeSnapshot(
            $separateRoot.'/spelldata_Live_2002-01-03_00_00_00.txt',
            ['id', 'name', 'spellicon'],
            [['10', '', '']],
        );
        $this->writeSnapshot(
            $separateRoot.'/spelldata_Live_2002-01-04_00_00_00.txt',
            ['id', 'mana'],
            [['10', '200']],
        );

        $this->compiler()->compile($separateRoot, $separateArtifacts);
        $cleared = (new SpellHistoryRepository($separateArtifacts))->spell(10);
        $this->assertNull($cleared['latest_name']);
        $this->assertNull($cleared['latest_icon']);
    }

    public function test_activation_recovers_windows_style_backup_crash_states(): void
    {
        $result = $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        $current = $this->artifactDirectory.'/CURRENT';
        $backup = $this->artifactDirectory.'/.CURRENT.bak';

        rename($current, $backup);
        $recovered = $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        $this->assertTrue($recovered['reused']);
        $this->assertSame($result['dataset'], trim((string) file_get_contents($current)));
        $this->assertFileDoesNotExist($backup);

        copy($current, $backup);
        $cleaned = $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        $this->assertTrue($cleaned['reused']);
        $this->assertSame($result['dataset'], trim((string) file_get_contents($current)));
        $this->assertFileDoesNotExist($backup);
    }

    public function test_activation_refuses_a_corrupt_recovery_pointer(): void
    {
        $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
        unlink($this->artifactDirectory.'/CURRENT');
        file_put_contents($this->artifactDirectory.'/.CURRENT.bak', "truncated\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CURRENT backup');
        $this->compiler()->compile($this->sourceDirectory, $this->artifactDirectory);
    }

    private function compiler(): SpellHistoryCompiler
    {
        return new SpellHistoryCompiler(new SnapshotLocator, new SpellCanonicalizer);
    }

    private function writeFixtureSnapshots(): void
    {
        $this->writeSnapshot(
            $this->sourceDirectory.'/spelldata_Live_2002-01-01_10_00_00.txt',
            ['id', 'name', 'warlevel', 'attrib1', 'base1', 'base2_1', 'calc1', 'desc1', 'updated'],
            [
                ['1', 'Virtue', '127', '254', '0', '0', '100', 'Derived old effect', '2002-01-01'],
                ['2', 'Gone Spell', '10', '254', '0', '0', '100', 'Derived', '2002-01-01'],
                ['4', 'Transient Spell', '15', '254', '0', '0', '100', 'Derived', '2002-01-01'],
            ],
        );
        $this->writeSnapshot(
            $this->sourceDirectory.'/spelldata_Live_2002-01-02_12_30_00.txt',
            ['id', 'name', 'warlevel', 'attrib1', 'base1', 'base2_1', 'calc1', 'unknown3', 'castmsg5', 'desc1', 'updated'],
            [
                ['1', 'Virtue', '5', '69', '2405', '3', '100', '7', 'Your virtue fades.', 'Derived new effect', '2002-01-02'],
                ['3', 'New Spell', '20', '254', '0', '0', '100', '1', '', 'Derived', '2002-01-02'],
            ],
        );
        $this->writeSnapshot(
            $this->sourceDirectory.'/spelldata_Live_2002-01-03_09_00_00.txt',
            ['id', 'name', 'warlevel', 'attrib1', 'base1', 'base2_1', 'calc1', 'unknown3', 'castmsg5', 'desc1', 'updated'],
            [
                ['1', 'Virtue', '5', '69', '2405', '3', '100', '8', 'Your virtue fades.', 'Exporter wording changed', '2002-01-03'],
                ['3', 'New Spell', '20', '254', '0', '0', '100', '1', '', 'Derived', '2002-01-03'],
                ['4', 'Transient Spell', '15', '254', '0', '0', '100', '4', '', 'Derived', '2002-01-03'],
            ],
        );
        $this->writeSnapshot(
            $this->sourceDirectory.'/spelldata_Live_2002-01-04_09_00_00.txt',
            ['id', 'name', 'warlevel', 'attrib1', 'base1', 'base2_1', 'calc1', 'unknown3', 'castmsg5', 'desc1', 'updated'],
            [
                ['1', 'Virtue', '5', '69', '2405', '3', '100', '8', 'Your virtue fades.', 'Exporter wording changed', '2002-01-04'],
                ['2', 'Gone Spell', '11', '254', '0', '0', '100', '2', '', 'Derived', '2002-01-04'],
                ['3', 'New Spell', '20', '254', '0', '0', '100', '1', '', 'Derived', '2002-01-04'],
                ['4', 'Transient Spell', '15', '254', '0', '0', '100', '4', '', 'Derived', '2002-01-04'],
            ],
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function writeSnapshot(string $path, array $headers, array $rows): void
    {
        $handle = fopen($path, 'wb');
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}

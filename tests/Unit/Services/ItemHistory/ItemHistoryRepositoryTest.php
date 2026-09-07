<?php

namespace Tests\Unit\Services\ItemHistory;

use App\Services\ItemHistory\ItemHistoryArtifact;
use App\Services\ItemHistory\ItemHistoryRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ItemHistoryRepositoryTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/item-history-repository-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_reads_the_sharded_item_artifact_and_paginates_newest_first(): void
    {
        $this->writeArtifact(20_542, $this->artifact());

        $repository = new ItemHistoryRepository($this->temporaryDirectory);
        $firstPage = $repository->forItem(20_542, 1, 1);
        $secondPage = $repository->forItem(20_542, 2, 1);

        $this->assertSame('dc', substr(hash('sha256', '20542'), 0, 2));
        $this->assertSame('items/dc/20542.json', ItemHistoryArtifact::itemRelativePath(20_542));
        $this->assertSame(2_020, $firstPage['revisions'][0]['entry_id']);
        $this->assertSame(2_019, $secondPage['revisions'][0]['entry_id']);
        $this->assertTrue($firstPage['pagination']['has_more']);
        $this->assertTrue($secondPage['pagination']['has_previous']);
        $this->assertNull($repository->forItem(999_999));
    }

    public function test_it_keeps_reading_the_legacy_flat_layout_when_no_activation_pointer_exists(): void
    {
        $legacy = $this->artifact();
        $legacy['latest_name'] = 'Legacy flat artifact';
        $this->writeArtifact(20_542, $legacy);

        $repository = new ItemHistoryRepository($this->temporaryDirectory);

        $this->assertSame('Legacy flat artifact', $repository->item(20_542)['latest_name']);
    }

    public function test_it_observes_the_dataset_selected_by_current_and_future_pointer_changes(): void
    {
        $legacy = $this->artifact();
        $legacy['latest_name'] = 'Legacy flat artifact';
        $this->writeArtifact(20_542, $legacy);

        $firstKey = str_repeat('a', 64);
        $first = $this->artifact();
        $first['latest_name'] = 'First immutable dataset';
        $this->createDataset($firstKey, $first);

        $secondKey = str_repeat('b', 64);
        $second = $this->artifact();
        $second['latest_name'] = 'Second immutable dataset';
        $this->createDataset($secondKey, $second);

        file_put_contents($this->temporaryDirectory.'/CURRENT', $firstKey."\n");
        $repository = new ItemHistoryRepository($this->temporaryDirectory);
        $this->assertSame('First immutable dataset', $repository->item(20_542)['latest_name']);

        file_put_contents($this->temporaryDirectory.'/CURRENT', $secondKey."\n");
        $this->assertSame('Second immutable dataset', $repository->item(20_542)['latest_name']);
    }

    public function test_it_uses_the_activation_backup_only_when_current_is_absent(): void
    {
        $datasetKey = str_repeat('a', 64);
        $artifact = $this->artifact();
        $artifact['latest_name'] = 'Recovered immutable dataset';
        $this->createDataset($datasetKey, $artifact);
        file_put_contents($this->temporaryDirectory.'/.CURRENT.bak', $datasetKey."\n");

        $repository = new ItemHistoryRepository($this->temporaryDirectory);
        $this->assertSame('Recovered immutable dataset', $repository->item(20_542)['latest_name']);

        file_put_contents($this->temporaryDirectory.'/CURRENT', "invalid\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CURRENT has an invalid size');
        $repository->item(20_542);
    }

    public function test_it_does_not_fall_back_to_legacy_data_for_a_missing_active_dataset(): void
    {
        $this->writeArtifact(20_542, $this->artifact());
        file_put_contents($this->temporaryDirectory.'/CURRENT', str_repeat('a', 64)."\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('active item history dataset is missing or unsafe');
        (new ItemHistoryRepository($this->temporaryDirectory))->item(20_542);
    }

    public function test_it_rejects_an_incomplete_active_dataset(): void
    {
        $datasetKey = str_repeat('a', 64);
        $datasetRoot = $this->temporaryDirectory.'/datasets/'.$datasetKey;
        mkdir($datasetRoot.'/items', 0755, true);
        file_put_contents($datasetRoot.'/manifest.json', '{}');
        file_put_contents($this->temporaryDirectory.'/CURRENT', $datasetKey."\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing or unsafe COMPLETE.json');
        (new ItemHistoryRepository($this->temporaryDirectory))->item(20_542);
    }

    public function test_it_does_not_read_legacy_item_backups_from_an_immutable_dataset(): void
    {
        $datasetKey = str_repeat('a', 64);
        $this->createDataset($datasetKey, $this->artifact());
        $datasetRoot = $this->temporaryDirectory.'/datasets/'.$datasetKey;
        $artifactPath = $datasetRoot.'/'.ItemHistoryArtifact::itemRelativePath(20_542);
        rename($artifactPath, $artifactPath.'.bak');
        file_put_contents($this->temporaryDirectory.'/CURRENT', $datasetKey."\n");

        $this->assertNull((new ItemHistoryRepository($this->temporaryDirectory))->item(20_542));
    }

    public function test_it_rejects_a_completion_marker_for_a_different_dataset(): void
    {
        $datasetKey = str_repeat('a', 64);
        $this->createDataset($datasetKey, $this->artifact());
        file_put_contents(
            $this->temporaryDirectory.'/datasets/'.$datasetKey.'/COMPLETE.json',
            json_encode(['dataset' => str_repeat('b', 64)], JSON_THROW_ON_ERROR),
        );
        file_put_contents($this->temporaryDirectory.'/CURRENT', $datasetKey."\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('completion marker does not match CURRENT');
        (new ItemHistoryRepository($this->temporaryDirectory))->item(20_542);
    }

    public function test_it_normalizes_compatible_crawler_aliases_without_weakening_the_core_schema(): void
    {
        $artifact = $this->artifact();
        unset($artifact['revisions'][1]['observed_precision']);
        $artifact['revisions'][1]['observed_at_precision'] = 'second';
        $artifact['revisions'][1]['changes'] = [['text' => 'AC changed from 10 to 12']];
        $artifact['revisions'][1]['detail'] = [
            'name' => 'Ceremonial Iksar Chestplate',
            'icon' => 512,
            'display_lines' => ['AC: 12'],
        ];
        unset($artifact['revisions'][1]['capture_sha256']);
        $artifact['revisions'][1]['capture'] = ['sha256' => str_repeat('a', 64)];
        $this->writeArtifact(20_542, $artifact);

        $revision = (new ItemHistoryRepository($this->temporaryDirectory))->item(20_542)['revisions'][1];

        $this->assertSame('second', $revision['observed_precision']);
        $this->assertSame('unknown', $revision['changes'][0]['operation']);
        $this->assertSame('AC changed from 10 to 12', $revision['changes'][0]['display']);
        $this->assertSame(['AC: 12'], $revision['detail']['snapshot_lines']);
        $this->assertSame(str_repeat('a', 64), $revision['capture_sha256']);
    }

    public function test_direct_detail_artifacts_allow_only_an_absent_or_version_one_parser(): void
    {
        $artifact = $this->artifact();
        $artifact['parser_format_version'] = ItemHistoryArtifact::DIRECT_DETAIL_PARSER_FORMAT_VERSION;
        $this->writeArtifact(20_542, $artifact);

        $loaded = (new ItemHistoryRepository($this->temporaryDirectory))->item(20_542);

        $this->assertSame(ItemHistoryArtifact::DIRECT_DETAIL_PARSER_FORMAT_VERSION, $loaded['parser_format_version']);

        $invalidRoot = $this->temporaryDirectory.'/invalid-direct-parser';
        mkdir($invalidRoot);
        $artifact['parser_format_version'] = ItemHistoryArtifact::REVERSIBLE_DELTA_PARSER_FORMAT_VERSION;
        $this->writeArtifact(20_542, $artifact, $invalidRoot);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is inconsistent');

        (new ItemHistoryRepository($invalidRoot))->item(20_542);
    }

    public function test_it_observes_an_independently_atomically_replaced_item_file(): void
    {
        $this->writeArtifact(20_542, $this->artifact());
        $repository = new ItemHistoryRepository($this->temporaryDirectory);
        $this->assertSame('Ceremonial Iksar Chestplate', $repository->item(20_542)['latest_name']);

        $replacement = $this->artifact();
        $replacement['latest_name'] = 'Updated Chestplate';
        $path = $this->artifactPath(20_542);
        $temporary = dirname($path).'/.20542-'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temporary, json_encode($replacement, JSON_THROW_ON_ERROR));
        rename($temporary, $path);

        $this->assertSame('Updated Chestplate', $repository->item(20_542)['latest_name']);
    }

    public function test_it_reads_a_valid_backup_only_when_the_primary_is_absent(): void
    {
        $this->writeArtifact(20_542, $this->artifact());
        $path = $this->artifactPath(20_542);
        rename($path, $path.'.bak');

        $repository = new ItemHistoryRepository($this->temporaryDirectory);

        $this->assertSame('Ceremonial Iksar Chestplate', $repository->item(20_542)['latest_name']);

        file_put_contents($path, '{invalid-json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        $repository->item(20_542);
    }

    public function test_it_rejects_a_symbolic_link_backup(): void
    {
        $path = $this->artifactPath(20_542);
        mkdir(dirname($path), 0755, true);
        $target = $this->temporaryDirectory.'/outside.json';
        file_put_contents($target, json_encode($this->artifact(), JSON_THROW_ON_ERROR));
        if (! @symlink($target, $path.'.bak')) {
            $this->markTestSkipped('Creating symbolic links is not permitted in this test environment.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be a symbolic link');
        (new ItemHistoryRepository($this->temporaryDirectory))->item(20_542);
    }

    public function test_it_rejects_inconsistent_mismatched_or_non_chronological_artifacts(): void
    {
        $invalidArtifacts = [];

        $incomplete = $this->artifact();
        $incomplete['complete'] = false;
        $invalidArtifacts[] = $incomplete;

        $wrongCount = $this->artifact();
        $wrongCount['revision_count'] = 99;
        $invalidArtifacts[] = $wrongCount;

        $mismatched = $this->artifact();
        $mismatched['item_id'] = 99;
        $invalidArtifacts[] = $mismatched;

        $nonChronological = $this->artifact();
        $nonChronological['revisions'][1]['observed_at'] = '2018-01-01T00:00:00';
        $invalidArtifacts[] = $nonChronological;

        $emptyHistory = $this->artifact();
        $emptyHistory['sources'] = [];
        $emptyHistory['revisions'] = [];
        $emptyHistory['revision_count'] = 0;
        $emptyHistory['first_observed_at'] = null;
        $emptyHistory['last_observed_at'] = null;
        $invalidArtifacts[] = $emptyHistory;

        $missingObservation = $this->artifact();
        $missingObservation['revisions'][0]['observed_at'] = null;
        $invalidArtifacts[] = $missingObservation;

        $emptyChanges = $this->artifact();
        $emptyChanges['revisions'][0]['changes'] = [];
        $invalidArtifacts[] = $emptyChanges;

        foreach ($invalidArtifacts as $index => $artifact) {
            $root = $this->temporaryDirectory.'/case-'.$index;
            mkdir($root);
            $this->writeArtifact(20_542, $artifact, $root);

            try {
                (new ItemHistoryRepository($root))->item(20_542);
                $this->fail("Invalid artifact case {$index} was accepted.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_complete_artifact_requires_detail_and_a_capture_hash_for_every_revision(): void
    {
        $missingDetail = $this->artifact();
        unset($missingDetail['revisions'][0]['detail']);

        $missingCapture = $this->artifact();
        unset($missingCapture['revisions'][1]['capture_sha256']);

        $invalidCapture = $this->artifact();
        $invalidCapture['revisions'][1]['capture_sha256'] = 'not-a-sha256';

        $emptySnapshot = $this->artifact();
        $emptySnapshot['revisions'][0]['detail']['snapshot_lines'] = [];

        foreach ([$missingDetail, $missingCapture, $invalidCapture, $emptySnapshot] as $index => $artifact) {
            $root = $this->temporaryDirectory.'/required-capture-'.$index;
            mkdir($root);
            $this->writeArtifact(20_542, $artifact, $root);

            try {
                (new ItemHistoryRepository($root))->item(20_542);
                $this->fail("Incomplete revision capture case {$index} was accepted.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_accepts_reversible_delta_artifacts_with_mixed_optional_direct_details(): void
    {
        $artifact = $this->reversibleArtifact();
        $this->writeArtifact(20_542, $artifact);

        $repository = new ItemHistoryRepository($this->temporaryDirectory);
        $item = $repository->item(20_542);
        $page = $repository->forItem(20_542);

        $this->assertSame(ItemHistoryArtifact::REVERSIBLE_DELTA_FORMAT_VERSION, $item['format_version']);
        $this->assertSame('reconstructed', $item['revisions'][0]['detail_fidelity']);
        $this->assertArrayNotHasKey('detail', $item['revisions'][0]);
        $this->assertArrayNotHasKey('capture_sha256', $item['revisions'][0]);
        $this->assertSame('captured', $item['revisions'][1]['detail_fidelity']);
        $this->assertSame(['AC: 12'], $item['revisions'][1]['detail']['snapshot_lines']);
        $this->assertTrue($page['archive']['is_reconstructed']);
        $this->assertSame('reconstructed', $page['archive']['coverage']['historical_state']);
        $this->assertSame(1, $page['archive']['coverage']['direct_detail_count']);
    }

    public function test_it_accepts_exact_redundant_lucy_transitions_as_verified_evidence(): void
    {
        $artifact = $this->reversibleArtifact();
        $artifact['revisions'][] = [
            'entry_id' => 2_021,
            'source' => 'Live',
            'observed_at' => '2021-03-03T14:00:00',
            'observed_precision' => 'minute',
            'type' => 'changed',
            'changes' => [[
                'operation' => 'changed',
                'field' => 'ac',
                'before' => '10',
                'after' => '12',
                'display' => 'Repeated AC transition',
            ]],
            'history_capture_sha256s' => [str_repeat('d', 64)],
        ];
        $artifact['revision_count'] = 3;
        $artifact['last_observed_at'] = '2021-03-03T14:00:00';
        $artifact['reconstruction']['sources']['Live']['revision_count'] = 3;
        $artifact['reconstruction']['sources']['Live']['change_count'] = 3;
        $artifact['reconstruction']['sources']['Live']['continuity_checks'] = 1;
        $this->writeArtifact(20_542, $artifact);

        $item = (new ItemHistoryRepository($this->temporaryDirectory))->item(20_542);

        $this->assertCount(3, $item['revisions']);
        $this->assertSame(2_021, $item['revisions'][2]['entry_id']);

        $identity = $this->reversibleArtifact();
        $identity['revisions'][1]['changes'][0]['after'] = '10';
        $identityRoot = $this->temporaryDirectory.'/identity-transition';
        mkdir($identityRoot);
        $this->writeArtifact(20_542, $identity, $identityRoot);
        $this->assertSame(
            '10',
            (new ItemHistoryRepository($identityRoot))->item(20_542)['revisions'][1]['changes'][0]['after'],
        );
    }

    public function test_it_rejects_malformed_reversible_delta_provenance_and_chains(): void
    {
        $invalidArtifacts = [];

        $unsupportedAlgorithm = $this->reversibleArtifact();
        $unsupportedAlgorithm['reconstruction']['algorithm'] = 'unreviewed-algorithm';
        $invalidArtifacts[] = $unsupportedAlgorithm;

        $missingHistoryEvidence = $this->reversibleArtifact();
        $missingHistoryEvidence['evidence']['history_capture_sha256s'] = [];
        $invalidArtifacts[] = $missingHistoryEvidence;

        $mismatchedRawEvidence = $this->reversibleArtifact();
        $mismatchedRawEvidence['evidence']['current_raw_capture_sha256'] = str_repeat('9', 64);
        $invalidArtifacts[] = $mismatchedRawEvidence;

        $missingRevisionEvidence = $this->reversibleArtifact();
        unset($missingRevisionEvidence['revisions'][0]['history_capture_sha256s']);
        $invalidArtifacts[] = $missingRevisionEvidence;

        $unknownRevisionEvidence = $this->reversibleArtifact();
        $unknownRevisionEvidence['revisions'][0]['history_capture_sha256s'] = [str_repeat('8', 64)];
        $invalidArtifacts[] = $unknownRevisionEvidence;

        $incompleteRevisionEvidence = $this->reversibleArtifact();
        $incompleteRevisionEvidence['evidence']['history_capture_sha256s'][] = str_repeat('8', 64);
        $invalidArtifacts[] = $incompleteRevisionEvidence;

        $missingDirectCapture = $this->reversibleArtifact();
        unset($missingDirectCapture['revisions'][1]['capture_sha256']);
        $invalidArtifacts[] = $missingDirectCapture;

        $orphanDirectCapture = $this->reversibleArtifact();
        $orphanDirectCapture['revisions'][0]['capture_sha256'] = str_repeat('7', 64);
        $invalidArtifacts[] = $orphanDirectCapture;

        $wrongAnchorStatus = $this->reversibleArtifact();
        $wrongAnchorStatus['reconstruction']['sources']['Live']['status'] = 'chain-verified-unanchored';
        $invalidArtifacts[] = $wrongAnchorStatus;

        $wrongContinuityCount = $this->reversibleArtifact();
        $wrongContinuityCount['reconstruction']['sources']['Live']['continuity_checks'] = 1;
        $invalidArtifacts[] = $wrongContinuityCount;

        $brokenChain = $this->reversibleArtifact();
        $brokenChain['revisions'][1]['changes'][] = [
            'operation' => 'changed',
            'field' => 'ac',
            'before' => '99',
            'after' => '13',
            'display' => 'AC changed from 99 to 13',
        ];
        $brokenChain['reconstruction']['sources']['Live']['change_count'] = 3;
        $brokenChain['reconstruction']['sources']['Live']['continuity_checks'] = 1;
        $invalidArtifacts[] = $brokenChain;

        $nonReversible = $this->reversibleArtifact();
        $nonReversible['revisions'][1]['changes'][0] = [
            'operation' => 'unknown',
            'field' => null,
            'before' => null,
            'after' => null,
            'display' => 'Unrecognized change',
        ];
        $nonReversible['reconstruction']['sources']['Live']['tracked_field_count'] = 0;
        $invalidArtifacts[] = $nonReversible;

        $malformedInitial = $this->reversibleArtifact();
        $malformedInitial['revisions'][0]['changes'][0]['field'] = 'ac';
        $invalidArtifacts[] = $malformedInitial;

        foreach ($invalidArtifacts as $index => $artifact) {
            $root = $this->temporaryDirectory.'/reversible-'.$index;
            mkdir($root);
            $this->writeArtifact(20_542, $artifact, $root);

            try {
                (new ItemHistoryRepository($root))->item(20_542);
                $this->fail("Malformed reversible artifact case {$index} was accepted.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_rejects_nested_structures_and_render_values_over_safe_limits(): void
    {
        $tooManyRevisions = $this->artifact();
        $tooManyRevisions['revisions'] = array_fill(0, 20_001, []);
        $tooManyRevisions['revision_count'] = 20_001;

        $tooManyChanges = $this->artifact();
        $tooManyChanges['revisions'][0]['changes'] = array_fill(
            0,
            513,
            $tooManyChanges['revisions'][0]['changes'][0],
        );

        $tooManySnapshotLines = $this->artifact();
        $tooManySnapshotLines['revisions'][0]['detail']['snapshot_lines'] = array_fill(0, 1_025, 'line');

        $oversizedDisplay = $this->artifact();
        $oversizedDisplay['revisions'][0]['changes'][0]['display'] = str_repeat('x', 16_385);

        $blankDisplay = $this->artifact();
        $blankDisplay['revisions'][0]['changes'][0]['display'] = '   ';

        $blankField = $this->artifact();
        $blankField['revisions'][0]['changes'][0]['field'] = ' ';

        $nestedBeforeValue = $this->artifact();
        $nestedBeforeValue['revisions'][0]['changes'][0]['before'] = ['crafted' => 'value'];

        $oversizedRevision = $this->artifact();
        $oversizedRevision['revisions'][0]['detail']['snapshot_lines'] = array_fill(
            0,
            43,
            str_repeat('&', 8_000),
        );

        $oversizedUnknownContainer = $this->artifact();
        $oversizedUnknownContainer['crafted'] = array_fill(0, 20_001, 0);

        foreach ([
            $tooManyRevisions,
            $tooManyChanges,
            $tooManySnapshotLines,
            $oversizedDisplay,
            $blankDisplay,
            $blankField,
            $nestedBeforeValue,
            $oversizedRevision,
            $oversizedUnknownContainer,
        ] as $index => $artifact) {
            $root = $this->temporaryDirectory.'/complexity-'.$index;
            mkdir($root);
            $this->writeArtifact(20_542, $artifact, $root);

            try {
                (new ItemHistoryRepository($root))->item(20_542);
                $this->fail("Unsafe artifact complexity case {$index} was accepted.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_accepts_a_measured_high_revision_corpus_within_the_reader_limits(): void
    {
        $artifact = $this->artifact();
        $revision = $artifact['revisions'][0];
        $artifact['revisions'] = array_fill(0, 13_590, $revision);
        foreach ($artifact['revisions'] as $index => &$entry) {
            $entry['entry_id'] = $index + 1;
        }
        unset($entry);
        $artifact['revision_count'] = 13_590;
        $artifact['first_observed_at'] = $revision['observed_at'];
        $artifact['last_observed_at'] = $revision['observed_at'];
        $this->writeArtifact(20_542, $artifact);

        $json = file_get_contents($this->artifactPath(20_542));
        $this->assertIsString($json);
        $this->assertGreaterThan(
            100_000,
            substr_count($json, '{') + substr_count($json, '[') + substr_count($json, ','),
        );
        $this->assertGreaterThan(200_000, $this->decodedValueCount($artifact));

        $loaded = (new ItemHistoryRepository($this->temporaryDirectory))->item(20_542);

        $this->assertSame(13_590, $loaded['revision_count']);
        $this->assertCount(13_590, $loaded['revisions']);
    }

    public function test_it_keeps_rejecting_aggregate_render_estimates_above_128_mib(): void
    {
        $artifact = $this->artifact();
        $revision = $artifact['revisions'][0];
        $revision['changes'][0]['display'] = str_repeat('x', 800);
        $artifact['revisions'] = array_fill(0, 12_000, $revision);
        foreach ($artifact['revisions'] as $index => &$entry) {
            $entry['entry_id'] = $index + 1;
        }
        unset($entry);
        $artifact['revision_count'] = 12_000;
        $artifact['first_observed_at'] = $revision['observed_at'];
        $artifact['last_observed_at'] = $revision['observed_at'];
        $this->writeArtifact(20_542, $artifact);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large to render safely');

        (new ItemHistoryRepository($this->temporaryDirectory))->item(20_542);
    }

    public function test_pagination_also_obeys_the_estimated_page_render_budget(): void
    {
        $artifact = $this->artifact();
        $largeSnapshot = array_fill(0, 25, str_repeat('&', 8_000));
        foreach ($artifact['revisions'] as &$revision) {
            $revision['detail']['snapshot_lines'] = $largeSnapshot;
        }
        unset($revision);
        $this->writeArtifact(20_542, $artifact);

        $repository = new ItemHistoryRepository($this->temporaryDirectory);
        $firstPage = $repository->forItem(20_542, 1, 100);
        $secondPage = $repository->forItem(20_542, 2, 100);

        $this->assertSame(2, $firstPage['pagination']['last_page']);
        $this->assertCount(1, $firstPage['revisions']);
        $this->assertCount(1, $secondPage['revisions']);
        $this->assertSame(2_020, $firstPage['revisions'][0]['entry_id']);
        $this->assertSame(2_019, $secondPage['revisions'][0]['entry_id']);
        $this->assertNull($repository->forItem(20_542, 3, 100));
    }

    public function test_it_rejects_invalid_ids_pages_and_page_sizes(): void
    {
        $repository = new ItemHistoryRepository($this->temporaryDirectory);

        foreach ([
            static fn () => $repository->item(0),
            static fn () => $repository->forItem(1, 0),
            static fn () => $repository->forItem(1, 1, 101),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Invalid repository input was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        return [
            'schema' => ItemHistoryArtifact::SCHEMA,
            'artifact_type' => 'item',
            'format_version' => ItemHistoryArtifact::FORMAT_VERSION,
            'item_id' => 20_542,
            'generated_at' => '2026-08-23T03:00:00Z',
            'latest_name' => 'Ceremonial Iksar Chestplate',
            'latest_icon' => 512,
            'first_observed_at' => '2019-01-01T12:30:00',
            'last_observed_at' => '2020-02-02T13:45:10',
            'revision_count' => 2,
            'sources' => ['Live'],
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
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function reversibleArtifact(): array
    {
        $artifact = $this->artifact();
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
        unset($artifact['revisions'][0]['detail'], $artifact['revisions'][0]['capture_sha256']);

        return $artifact;
    }

    /** @param array<string, mixed> $artifact */
    private function writeArtifact(int $itemId, array $artifact, ?string $root = null): void
    {
        $root ??= $this->temporaryDirectory;
        $path = $root.'/'.ItemHistoryArtifact::itemRelativePath($itemId);
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $artifact */
    private function createDataset(string $datasetKey, array $artifact): void
    {
        $root = $this->temporaryDirectory.'/datasets/'.$datasetKey;
        mkdir($root.'/items', 0755, true);
        file_put_contents($root.'/manifest.json', '{}');
        file_put_contents($root.'/COMPLETE.json', json_encode([
            'dataset' => $datasetKey,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->writeArtifact(20_542, $artifact, $root);
    }

    private function artifactPath(int $itemId): string
    {
        return $this->temporaryDirectory.'/'.ItemHistoryArtifact::itemRelativePath($itemId);
    }

    private function decodedValueCount(mixed $value): int
    {
        if (! is_array($value)) {
            return 1;
        }

        $count = 1;
        foreach ($value as $nestedValue) {
            $count += $this->decodedValueCount($nestedValue);
        }

        return $count;
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

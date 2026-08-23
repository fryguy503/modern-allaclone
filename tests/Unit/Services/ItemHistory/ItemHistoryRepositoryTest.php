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

    public function test_it_rejects_nested_structures_and_render_values_over_safe_limits(): void
    {
        $tooManyRevisions = $this->artifact();
        $tooManyRevisions['revisions'] = array_fill(0, 5_001, []);
        $tooManyRevisions['revision_count'] = 5_001;

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

    /** @param array<string, mixed> $artifact */
    private function writeArtifact(int $itemId, array $artifact, ?string $root = null): void
    {
        $root ??= $this->temporaryDirectory;
        $path = $root.'/'.ItemHistoryArtifact::itemRelativePath($itemId);
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function artifactPath(int $itemId): string
    {
        return $this->temporaryDirectory.'/'.ItemHistoryArtifact::itemRelativePath($itemId);
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

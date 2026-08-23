<?php

namespace Tests\Unit\Services\SpellHistory;

use App\Services\SpellHistory\SpellCanonicalizer;
use App\Services\SpellHistory\SpellHistoryArtifact;
use App\Services\SpellHistory\SpellHistoryArtifactPruner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SpellHistoryArtifactPrunerTest extends TestCase
{
    private string $temporaryDirectory;

    private string $artifactDirectory;

    private string $datasetsDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().'/spell-history-pruner-'.bin2hex(random_bytes(8));
        $this->artifactDirectory = $this->temporaryDirectory.'/artifacts';
        $this->datasetsDirectory = $this->artifactDirectory.'/datasets';
        mkdir($this->datasetsDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_dry_run_and_apply_keep_active_and_recent_rollback_datasets(): void
    {
        $now = 1_800_000_000;
        $active = str_repeat('a', 64);
        $rollback = str_repeat('b', 64);
        $expired = str_repeat('c', 64);
        $this->createDataset($active, $now - 20_000);
        $this->createDataset($rollback, $now - 10_000);
        $this->createDataset($expired, $now - 20_000);
        $this->writeCurrent($active, $now - 5_000);

        $pruner = new SpellHistoryArtifactPruner;
        $preview = $pruner->prune($this->artifactDirectory, 1, 3_600, false, $now);
        $previewActions = array_column($preview['datasets'], 'action', 'dataset');

        $this->assertSame('kept_active', $previewActions[$active]);
        $this->assertSame('kept_rollback', $previewActions[$rollback]);
        $this->assertSame('would_delete', $previewActions[$expired]);
        $this->assertSame(1, $preview['would_delete_count']);
        $this->assertDirectoryExists($this->datasetsDirectory.'/'.$expired);

        $applied = $pruner->prune($this->artifactDirectory, 1, 3_600, true, $now);

        $this->assertSame(1, $applied['deleted_count']);
        $this->assertDirectoryExists($this->datasetsDirectory.'/'.$active);
        $this->assertDirectoryExists($this->datasetsDirectory.'/'.$rollback);
        $this->assertDirectoryDoesNotExist($this->datasetsDirectory.'/'.$expired);
    }

    public function test_activation_grace_and_pending_activation_candidates_are_both_protected(): void
    {
        $now = 1_800_000_000;
        $active = str_repeat('a', 64);
        $inactive = str_repeat('b', 64);
        $this->createDataset($active, $now - 20_000);
        $this->createDataset($inactive, $now - 20_000);
        $this->writeCurrent($active, $now - 60);

        $pruner = new SpellHistoryArtifactPruner;
        $activationGrace = $pruner->prune($this->artifactDirectory, 0, 3_600, true, $now);
        $this->assertSame(
            'kept_activation_grace',
            array_column($activationGrace['datasets'], 'action', 'dataset')[$inactive],
        );

        touch($this->artifactDirectory.'/CURRENT', $now - 10_000);
        touch($this->datasetsDirectory.'/'.$inactive.'/COMPLETE.json', $now - 60);
        $pendingActivation = $pruner->prune($this->artifactDirectory, 0, 3_600, true, $now);
        $this->assertSame(
            'kept_pending_activation',
            array_column($pendingActivation['datasets'], 'action', 'dataset')[$inactive],
        );
        $this->assertDirectoryExists($this->datasetsDirectory.'/'.$inactive);
    }

    public function test_completed_inactive_datasets_from_an_older_format_can_be_pruned(): void
    {
        $now = 1_800_000_000;
        $active = str_repeat('a', 64);
        $old = str_repeat('b', 64);
        $this->createDataset($active, $now - 20_000);
        $this->createDataset(
            $old,
            $now - 20_000,
            max(1, SpellHistoryArtifact::FORMAT_VERSION - 1),
            SpellCanonicalizer::FORMAT_VERSION,
        );
        $this->writeCurrent($active, $now - 10_000);

        $result = (new SpellHistoryArtifactPruner)->prune(
            $this->artifactDirectory,
            0,
            0,
            true,
            $now,
        );

        $this->assertSame(1, $result['deleted_count']);
        $this->assertDirectoryDoesNotExist($this->datasetsDirectory.'/'.$old);
    }

    public function test_crash_leftover_tombstones_are_previewed_and_cleaned_on_a_later_run(): void
    {
        $now = 1_800_000_000;
        $active = str_repeat('a', 64);
        $old = str_repeat('b', 64);
        $this->createDataset($active, $now - 20_000);
        $this->createDataset($old, $now - 20_000);
        $this->writeCurrent($active, $now - 10_000);
        $tombstone = $this->artifactDirectory.'/.pruning-'.$old.'-'.str_repeat('c', 32);
        rename($this->datasetsDirectory.'/'.$old, $tombstone);

        $pruner = new SpellHistoryArtifactPruner;
        $preview = $pruner->prune($this->artifactDirectory, 0, 0, false, $now);
        $this->assertSame(1, $preview['would_delete_count']);
        $this->assertSame('would_delete_tombstone', $preview['tombstones'][0]['action']);
        $this->assertDirectoryExists($tombstone);

        $applied = $pruner->prune($this->artifactDirectory, 0, 0, true, $now);
        $this->assertSame(1, $applied['deleted_count']);
        $this->assertDirectoryDoesNotExist($tombstone);
        $this->assertDirectoryExists($this->datasetsDirectory.'/'.$active);
    }

    public function test_invalid_and_unrecognized_inactive_entries_are_skipped_not_deleted(): void
    {
        $now = 1_800_000_000;
        $active = str_repeat('a', 64);
        $invalid = str_repeat('b', 64);
        $this->createDataset($active, $now - 10_000);
        $this->writeCurrent($active, $now - 10_000);
        mkdir($this->datasetsDirectory.'/'.$invalid);
        file_put_contents($this->datasetsDirectory.'/'.$invalid.'/manifest.json', '{}');
        mkdir($this->datasetsDirectory.'/manual-backup');

        $result = (new SpellHistoryArtifactPruner)->prune(
            $this->artifactDirectory,
            0,
            0,
            true,
            $now,
        );

        $this->assertCount(2, $result['skipped']);
        $this->assertDirectoryExists($this->datasetsDirectory.'/'.$invalid);
        $this->assertDirectoryExists($this->datasetsDirectory.'/manual-backup');
    }

    public function test_symlinked_dataset_entry_aborts_before_any_deletion(): void
    {
        $now = 1_800_000_000;
        $active = str_repeat('a', 64);
        $expired = str_repeat('b', 64);
        $symlink = str_repeat('c', 64);
        $this->createDataset($active, $now - 20_000);
        $this->createDataset($expired, $now - 20_000);
        $this->writeCurrent($active, $now - 20_000);
        mkdir($this->temporaryDirectory.'/outside');
        if (! symlink($this->temporaryDirectory.'/outside', $this->datasetsDirectory.'/'.$symlink)) {
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        try {
            (new SpellHistoryArtifactPruner)->prune(
                $this->artifactDirectory,
                0,
                0,
                true,
                $now,
            );
            $this->fail('A symlinked dataset entry was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('symbolic links', $exception->getMessage());
        }
        $this->assertDirectoryExists($this->datasetsDirectory.'/'.$expired);
    }

    public function test_recovery_pointer_is_treated_as_active_when_current_is_absent(): void
    {
        $now = 1_800_000_000;
        $active = str_repeat('a', 64);
        $expired = str_repeat('b', 64);
        $this->createDataset($active, $now - 20_000);
        $this->createDataset($expired, $now - 20_000);
        file_put_contents($this->artifactDirectory.'/.CURRENT.bak', $active."\n");
        touch($this->artifactDirectory.'/.CURRENT.bak', $now - 10_000);

        $result = (new SpellHistoryArtifactPruner)->prune(
            $this->artifactDirectory,
            0,
            0,
            true,
            $now,
        );

        $this->assertSame($active, $result['active_dataset']);
        $this->assertDirectoryExists($this->datasetsDirectory.'/'.$active);
        $this->assertDirectoryDoesNotExist($this->datasetsDirectory.'/'.$expired);
    }

    public function test_unsafe_prune_lock_is_rejected(): void
    {
        mkdir($this->artifactDirectory.'/.prune.lock');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prune lock must be a regular file');
        (new SpellHistoryArtifactPruner)->prune($this->artifactDirectory);
    }

    private function createDataset(
        string $datasetKey,
        int $completedAt,
        int $formatVersion = SpellHistoryArtifact::FORMAT_VERSION,
        int $canonicalFormatVersion = SpellCanonicalizer::FORMAT_VERSION,
    ): void {
        $directory = $this->datasetsDirectory.'/'.$datasetKey;
        mkdir($directory, 0755, true);
        $manifest = [
            'schema' => SpellHistoryArtifact::SCHEMA,
            'artifact_type' => 'manifest',
            'format_version' => $formatVersion,
            'canonical_format_version' => $canonicalFormatVersion,
            'dataset' => $datasetKey,
            'snapshots' => [[]],
            'stats' => [
                'snapshot_count' => 1,
                'spell_count' => 1,
                'revision_count' => 1,
            ],
        ];
        $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($directory.'/manifest.json', $manifestJson);
        file_put_contents($directory.'/COMPLETE.json', json_encode([
            'schema' => SpellHistoryArtifact::SCHEMA,
            'artifact_type' => 'completion',
            'format_version' => $formatVersion,
            'canonical_format_version' => $canonicalFormatVersion,
            'dataset' => $datasetKey,
            'manifest_sha256' => hash('sha256', $manifestJson),
            'manifest_bytes' => strlen($manifestJson),
            'spell_count' => 1,
            'revision_count' => 1,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        touch($directory.'/COMPLETE.json', $completedAt);
    }

    private function writeCurrent(string $datasetKey, int $modifiedAt): void
    {
        file_put_contents($this->artifactDirectory.'/CURRENT', $datasetKey."\n");
        touch($this->artifactDirectory.'/CURRENT', $modifiedAt);
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

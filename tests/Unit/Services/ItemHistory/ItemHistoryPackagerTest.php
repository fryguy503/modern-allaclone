<?php

namespace Tests\Unit\Services\ItemHistory;

use App\Services\ItemHistory\ItemHistoryActivator;
use App\Services\ItemHistory\ItemHistoryArtifact;
use App\Services\ItemHistory\ItemHistoryDataset;
use App\Services\ItemHistory\ItemHistoryInstaller;
use App\Services\ItemHistory\ItemHistoryMutationLock;
use App\Services\ItemHistory\ItemHistoryPackager;
use App\Services\ItemHistory\ItemHistoryPermissions;
use App\Services\ItemHistory\ItemHistoryRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Support\CreatesItemHistoryPackageDataset;
use ZipArchive;

class ItemHistoryPackagerTest extends TestCase
{
    use CreatesItemHistoryPackageDataset;

    private string $temporaryDirectory;

    private string $artifactDirectory;

    private string $outputDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not installed.');
        }
        $this->temporaryDirectory = sys_get_temp_dir().'/item-history-packager-'.bin2hex(random_bytes(8));
        $this->artifactDirectory = $this->temporaryDirectory.'/artifacts';
        $this->outputDirectory = $this->temporaryDirectory.'/release';
        mkdir($this->artifactDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory)) {
            $this->removeItemHistoryPackageTree($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function test_it_packages_a_flat_completed_root_as_a_deterministic_immutable_dataset(): void
    {
        $paths = $this->createLegacyItemHistoryArtifacts($this->artifactDirectory, [20_542, 13_405]);
        $sourceHashes = array_map(static fn (string $path): string => hash_file('sha256', $path), $paths);

        $result = $this->packager()->create($this->artifactDirectory, $this->outputDirectory);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['dataset']);
        $this->assertSame(
            'modern-allaclone-item-history-f2-p2-2026-09-06-'.substr($result['dataset'], 0, 12).'.zip',
            $result['archive_name'],
        );
        $this->assertSame(hash_file('sha256', $result['zip_path']), $result['sha256']);
        $this->assertSame(
            "{$result['sha256']}  {$result['archive_name']}\n",
            file_get_contents($result['checksum_path']),
        );
        $this->assertSame(
            $sourceHashes,
            array_map(static fn (string $path): string => hash_file('sha256', $path), $paths),
        );
        $this->assertFileDoesNotExist($this->artifactDirectory.'/CURRENT');
        $this->assertDirectoryDoesNotExist($this->artifactDirectory.'/datasets');

        $descriptor = json_decode(file_get_contents($result['descriptor_path']), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(ItemHistoryDataset::PACKAGE_SCHEMA, $descriptor['schema']);
        $this->assertSame(ItemHistoryDataset::PACKAGE_VERSION, $descriptor['package_version']);
        $this->assertSame($result['dataset'], $descriptor['dataset']);
        $this->assertSame([2], $descriptor['artifact_format_versions']);
        $this->assertSame([2], $descriptor['parser_format_versions']);
        $this->assertSame('2019-01-01T12:30:00', $descriptor['first_observed_at']);
        $this->assertSame('2020-02-02T13:45:10', $descriptor['last_observed_at']);
        $this->assertSame('2026-09-06T21:12:54.246Z', $descriptor['latest_generated_at']);
        $this->assertSame(2, $descriptor['stats']['item_count']);
        $this->assertSame(4, $descriptor['stats']['revision_count']);
        $this->assertSame(4, $descriptor['stats']['file_count']);
        $this->assertSame($result['sha256'], $descriptor['archive']['sha256']);
        $this->assertSame($result['archive_bytes'], $descriptor['archive']['bytes']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['zip_path'], ZipArchive::RDONLY));
        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entries[] = $zip->getNameIndex($index);
            }
            $expected = [
                'package.json',
                "dataset/{$result['dataset']}/manifest.json",
                "dataset/{$result['dataset']}/COMPLETE.json",
                ...array_map(
                    fn (string $path): string => "dataset/{$result['dataset']}/".
                        str_replace('\\', '/', substr($path, strlen($this->artifactDirectory) + 1)),
                    $paths,
                ),
            ];
            sort($entries, SORT_STRING);
            sort($expected, SORT_STRING);
            $this->assertSame($expected, $entries);

            $package = json_decode($zip->getFromName('package.json'), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame($result['dataset'], $package['dataset']);
            $this->assertNull($package['archive']['sha256']);
            $this->assertNull($package['archive']['bytes']);

            $manifestPath = "dataset/{$result['dataset']}/manifest.json";
            $manifest = json_decode($zip->getFromName($manifestPath), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame(ItemHistoryDataset::DATASET_SCHEMA, $manifest['schema']);
            $this->assertSame(['items/67/13405.json', 'items/dc/20542.json'], array_column($manifest['files'], 'path'));
            $this->assertSame(2, $manifest['stats']['item_count']);

            $completion = json_decode(
                $zip->getFromName("dataset/{$result['dataset']}/COMPLETE.json"),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
            $this->assertSame(hash('sha256', $zip->getFromName($manifestPath)), $completion['manifest_sha256']);
            $this->assertSame(strlen($zip->getFromName($manifestPath)), $completion['manifest_bytes']);
        } finally {
            $zip->close();
        }

        $second = $this->packager()->create(
            $this->artifactDirectory,
            $this->temporaryDirectory.'/second-release',
            'second.zip',
        );
        $this->assertSame($result['dataset'], $second['dataset']);
    }

    public function test_it_packages_only_the_selected_activated_dataset(): void
    {
        $flat = $this->temporaryDirectory.'/flat';
        mkdir($flat);
        $this->createLegacyItemHistoryArtifacts($flat);
        $first = $this->packager()->create($flat, $this->temporaryDirectory.'/first-release');

        $active = $this->temporaryDirectory.'/active';
        mkdir($active.'/datasets', 0755, true);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($first['zip_path'], ZipArchive::RDONLY));
        try {
            $this->assertTrue($zip->extractTo($active));
        } finally {
            $zip->close();
        }
        $this->assertTrue(rename($active.'/dataset/'.$first['dataset'], $active.'/datasets/'.$first['dataset']));
        rmdir($active.'/dataset');
        file_put_contents($active.'/CURRENT', $first['dataset']."\n");
        mkdir($active.'/datasets/'.str_repeat('f', 64));
        file_put_contents($active.'/datasets/'.str_repeat('f', 64).'/partial.txt', 'not current');
        $this->removeItemHistoryPackageTree($this->temporaryDirectory.'/lucy-item-history-crawl');

        $second = $this->packager()->create($active, $this->outputDirectory);

        $this->assertSame($first['dataset'], $second['dataset']);
        $datasetEntries = array_values(array_diff(
            scandir($active.'/datasets/'.$first['dataset']),
            ['.', '..'],
        ));
        sort($datasetEntries, SORT_STRING);
        $this->assertSame(['COMPLETE.json', 'items', 'manifest.json'], $datasetEntries);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($second['zip_path'], ZipArchive::RDONLY));
        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entries[] = $zip->getNameIndex($index);
            }
            $this->assertStringNotContainsString(str_repeat('f', 64), implode("\n", $entries));
        } finally {
            $zip->close();
        }
    }

    public function test_v1_artifacts_package_without_a_parser_version_and_round_trip_through_the_installer(): void
    {
        $artifact = $this->directItemHistoryPackageArtifact(20_542);
        $path = $this->artifactDirectory.'/items/dc/20542.json';
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, $this->itemHistoryPackageJson($artifact));
        $this->createCompletedItemHistoryCrawlerWorkspace($this->artifactDirectory, [20_542]);

        $release = $this->packager()->create($this->artifactDirectory, $this->outputDirectory);
        $descriptor = json_decode(file_get_contents($release['descriptor_path']), true, 64, JSON_THROW_ON_ERROR);

        $this->assertSame([1], $descriptor['artifact_format_versions']);
        $this->assertSame([], $descriptor['parser_format_versions']);
        $this->assertStringContainsString('-f1-pnone-2026-09-06-', $release['archive_name']);

        $installedRoot = $this->temporaryDirectory.'/installed';
        $installer = new ItemHistoryInstaller(new ItemHistoryActivator, new ItemHistoryMutationLock);
        $installed = $installer->installFromFile(
            $release['zip_path'],
            $release['sha256'],
            $installedRoot,
        );

        $this->assertSame($release['dataset'], $installed['dataset']);
        $this->assertSame('Packaged Item 20542', (new ItemHistoryRepository($installedRoot))->item(20_542)['latest_name']);
    }

    public function test_v1_artifacts_with_declared_parser_version_one_record_that_parser(): void
    {
        $artifact = $this->directItemHistoryPackageArtifact(20_542);
        $artifact['parser_format_version'] = ItemHistoryArtifact::DIRECT_DETAIL_PARSER_FORMAT_VERSION;
        $path = $this->artifactDirectory.'/'.ItemHistoryArtifact::itemRelativePath(20_542);
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, $this->itemHistoryPackageJson($artifact));
        $this->createCompletedItemHistoryCrawlerWorkspace($this->artifactDirectory, [20_542]);

        $release = $this->packager()->create($this->artifactDirectory, $this->outputDirectory);
        $descriptor = json_decode(file_get_contents($release['descriptor_path']), true, 64, JSON_THROW_ON_ERROR);

        $this->assertSame([ItemHistoryArtifact::FORMAT_VERSION], $descriptor['artifact_format_versions']);
        $this->assertSame(
            [ItemHistoryArtifact::DIRECT_DETAIL_PARSER_FORMAT_VERSION],
            $descriptor['parser_format_versions'],
        );
        $this->assertStringContainsString('-f1-p1-2026-09-06-', $release['archive_name']);
    }

    public function test_mixed_direct_detail_and_reversible_delta_parser_versions_are_recorded(): void
    {
        $direct = $this->directItemHistoryPackageArtifact(13_405);
        $direct['parser_format_version'] = ItemHistoryArtifact::DIRECT_DETAIL_PARSER_FORMAT_VERSION;
        $delta = $this->itemHistoryPackageArtifact(20_542);
        foreach ([13_405 => $direct, 20_542 => $delta] as $itemId => $artifact) {
            $path = $this->artifactDirectory.'/'.ItemHistoryArtifact::itemRelativePath($itemId);
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $this->itemHistoryPackageJson($artifact));
        }
        $this->createCompletedItemHistoryCrawlerWorkspace($this->artifactDirectory, [13_405, 20_542]);

        $release = $this->packager()->create($this->artifactDirectory, $this->outputDirectory);
        $descriptor = json_decode(file_get_contents($release['descriptor_path']), true, 64, JSON_THROW_ON_ERROR);

        $this->assertSame(
            [ItemHistoryArtifact::FORMAT_VERSION, ItemHistoryArtifact::REVERSIBLE_DELTA_FORMAT_VERSION],
            $descriptor['artifact_format_versions'],
        );
        $this->assertSame(
            [
                ItemHistoryArtifact::DIRECT_DETAIL_PARSER_FORMAT_VERSION,
                ItemHistoryArtifact::REVERSIBLE_DELTA_PARSER_FORMAT_VERSION,
            ],
            $descriptor['parser_format_versions'],
        );
        $this->assertStringContainsString('-f1_2-p1_2-2026-09-06-', $release['archive_name']);
    }

    public function test_it_rejects_a_direct_detail_artifact_with_an_unsupported_parser_pair(): void
    {
        $artifact = $this->directItemHistoryPackageArtifact(20_542);
        $artifact['parser_format_version'] = ItemHistoryArtifact::REVERSIBLE_DELTA_PARSER_FORMAT_VERSION;
        $path = $this->artifactDirectory.'/'.ItemHistoryArtifact::itemRelativePath(20_542);
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, $this->itemHistoryPackageJson($artifact));
        $this->createCompletedItemHistoryCrawlerWorkspace($this->artifactDirectory, [20_542]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incompatible or incomplete envelope');
        $this->packager()->create($this->artifactDirectory, $this->outputDirectory);
    }

    public function test_it_uses_the_backup_activation_pointer_instead_of_stale_flat_items(): void
    {
        $flat = $this->temporaryDirectory.'/source-flat';
        mkdir($flat);
        $this->createLegacyItemHistoryArtifacts($flat, [20_542]);
        $first = $this->packager()->create($flat, $this->temporaryDirectory.'/source-release');

        $active = $this->temporaryDirectory.'/backup-active';
        mkdir($active.'/datasets', 0755, true);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($first['zip_path'], ZipArchive::RDONLY));
        try {
            $this->assertTrue($zip->extractTo($active));
        } finally {
            $zip->close();
        }
        $this->assertTrue(rename($active.'/dataset/'.$first['dataset'], $active.'/datasets/'.$first['dataset']));
        rmdir($active.'/dataset');
        file_put_contents($active.'/.CURRENT.bak', $first['dataset']."\n");

        // A migration may leave the old flat tree in place. It must never win
        // over the recoverable activation pointer.
        $this->createLegacyItemHistoryArtifacts($active, [13_405]);

        $result = $this->packager()->create($active, $this->outputDirectory);

        $this->assertSame($first['dataset'], $result['dataset']);
        $descriptor = json_decode(file_get_contents($result['descriptor_path']), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $descriptor['stats']['item_count']);
    }

    public function test_it_rejects_active_and_inactive_dataset_output_paths_before_creating_them(): void
    {
        $activeDataset = str_repeat('a', 64);
        $inactiveDataset = str_repeat('b', 64);
        mkdir($this->artifactDirectory.'/datasets/'.$activeDataset, 0755, true);
        mkdir($this->artifactDirectory.'/datasets/'.$inactiveDataset, 0755, true);
        file_put_contents($this->artifactDirectory.'/CURRENT', $activeDataset."\n");

        foreach ([$activeDataset, $inactiveDataset] as $dataset) {
            $output = $this->artifactDirectory.'/datasets/'.$dataset.'/release-output';

            try {
                $this->packager()->create($this->artifactDirectory, $output);
                $this->fail("Output inside dataset {$dataset} should be rejected.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('cannot overlap item-history artifact data', $exception->getMessage());
            }

            $this->assertDirectoryDoesNotExist($output);
        }
    }

    public function test_it_allows_the_default_releases_sibling_beside_a_flat_item_root(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory);
        $output = $this->artifactDirectory.'/releases';

        $result = $this->packager()->create($this->artifactDirectory, $output);

        $this->assertSame($output.'/'.$result['archive_name'], $result['zip_path']);
        $this->assertFileExists($result['zip_path']);
    }

    public function test_its_hard_limits_fit_the_default_installer_envelope(): void
    {
        $reflection = new ReflectionClass(ItemHistoryPackager::class);

        $this->assertSame(200_000, $reflection->getConstant('MAX_PACKAGE_FILES'));
        $this->assertSame(3_221_225_472, $reflection->getConstant('MAX_DATASET_BYTES'));
        $this->assertSame(1_073_741_824, $reflection->getConstant('MAX_RELEASE_ASSET_BYTES'));
    }

    public function test_it_normalizes_the_activation_lock_for_cross_user_readers(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('The POSIX permission normalizer is not used on Windows PHP.');
        }
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory);
        $chmodCalls = [];
        $permissions = new ItemHistoryPermissions(
            static function (string $path, int $mode) use (&$chmodCalls): bool {
                $chmodCalls[] = [$path, $mode];

                return true;
            },
            static fn (string $path): int => 0644,
        );
        $packager = new ItemHistoryPackager(new ItemHistoryMutationLock, $permissions);

        $packager->create($this->artifactDirectory, $this->outputDirectory);

        $this->assertSame(
            [[$this->artifactDirectory.'/.activation.lock', 0644]],
            $chmodCalls,
        );
    }

    public function test_it_accepts_a_chmod_ineffective_mount_when_the_activation_lock_reports_0777(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('The POSIX permission normalizer is not used on Windows PHP.');
        }
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory);
        $chmodCalls = 0;
        $permissions = new ItemHistoryPermissions(
            static function (string $path, int $mode) use (&$chmodCalls): bool {
                $chmodCalls++;

                return true;
            },
            static fn (string $path): int => 0777,
        );
        $packager = new ItemHistoryPackager(new ItemHistoryMutationLock, $permissions);

        $release = $packager->create($this->artifactDirectory, $this->outputDirectory);

        $this->assertSame(1, $chmodCalls);
        $this->assertFileExists($release['zip_path']);
    }

    public function test_it_rejects_an_activation_lock_that_remains_private_after_normalization(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('The POSIX permission normalizer is not used on Windows PHP.');
        }
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory);
        $permissions = new ItemHistoryPermissions(
            static fn (string $path, int $mode): bool => true,
            static fn (string $path): int => 0600,
        );
        $packager = new ItemHistoryPackager(new ItemHistoryMutationLock, $permissions);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not retain its required permissions');
        $packager->create($this->artifactDirectory, $this->outputDirectory);
    }

    public function test_it_rejects_windows_device_archive_names(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory);

        foreach ([
            'CON.zip',
            'PRN.zip',
            'aux.data.zip',
            'nul.payload.zip',
            'CLOCK$.zip',
            'com1.zip',
            'COM9.payload.zip',
            'lpt1.zip',
            'LPT9.backup.zip',
        ] as $index => $name) {
            try {
                $this->packager()->create(
                    $this->artifactDirectory,
                    $this->temporaryDirectory.'/reserved-'.$index,
                    $name,
                );
                $this->fail("Windows device archive name {$name} should be rejected.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('archive name', $exception->getMessage());
            }
        }
    }

    public function test_it_rejects_invalid_item_envelopes_and_unexpected_item_entries(): void
    {
        foreach (['parser', 'complete', 'gaps', 'id'] as $case) {
            $root = $this->temporaryDirectory.'/invalid-'.$case;
            mkdir($root);
            $artifact = $this->itemHistoryPackageArtifact(20_542);
            match ($case) {
                'parser' => $artifact['parser_format_version'] = 99,
                'complete' => $artifact['complete'] = false,
                'gaps' => $artifact['gaps'] = [['reason' => 'missing']],
                'id' => $artifact['item_id'] = 99,
            };
            $path = $root.'/items/dc/20542.json';
            mkdir(dirname($path), 0755, true);
            file_put_contents($path, $this->itemHistoryPackageJson($artifact));
            $this->createCompletedItemHistoryCrawlerWorkspace($root, [20_542]);

            try {
                $this->packager()->create($root, $this->temporaryDirectory.'/release-'.$case);
                $this->fail("Invalid {$case} artifact should be rejected.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('incompatible or incomplete envelope', $exception->getMessage());
            }
        }

        $root = $this->temporaryDirectory.'/unexpected';
        mkdir($root);
        $this->createLegacyItemHistoryArtifacts($root);
        file_put_contents($root.'/items/dc/unexpected.txt', 'unsafe');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected entry in item history shard');
        $this->packager()->create($root, $this->temporaryDirectory.'/unexpected-release');
    }

    public function test_it_detects_a_source_change_and_removes_partial_release_assets(): void
    {
        $paths = $this->createLegacyItemHistoryArtifacts($this->artifactDirectory, [20_542, 13_405]);
        $changed = false;

        try {
            $this->packager()->create(
                $this->artifactDirectory,
                $this->outputDirectory,
                null,
                function (string $stage, array $context) use (&$changed, $paths): void {
                    if (! $changed && $stage === 'archive' && ($context['current'] ?? null) === 1) {
                        file_put_contents($paths[20_542], "{}\n");
                        $changed = true;
                    }
                },
            );
            $this->fail('A changing source artifact should not be packaged.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('changed while packaging', $exception->getMessage());
        }

        $published = glob($this->outputDirectory.'/*') ?: [];
        $this->assertSame([], $published);
    }

    public function test_flat_packaging_requires_complete_progress_and_an_exact_queue_inventory(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory, [20_542]);
        $workspace = dirname($this->artifactDirectory).'/lucy-item-history-crawl';
        $progressPath = $workspace.'/state/progress.json';
        $progress = json_decode(file_get_contents($progressPath), true, 64, JSON_THROW_ON_ERROR);
        $progress['completed_items'] = 0;
        file_put_contents($progressPath, $this->itemHistoryPackageJson($progress));

        try {
            $this->packager()->create($this->artifactDirectory, $this->temporaryDirectory.'/incomplete-release');
            $this->fail('Incomplete crawler progress should not certify a flat artifact root.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('completed, gap-free crawler progress', $exception->getMessage());
        }

        $this->createCompletedItemHistoryCrawlerWorkspace($this->artifactDirectory, [13_405, 20_542]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('artifact count does not match the completed crawler queue');
        $this->packager()->create($this->artifactDirectory, $this->temporaryDirectory.'/partial-release');
    }

    public function test_flat_packaging_refuses_an_existing_crawler_lock(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory);
        $workspace = dirname($this->artifactDirectory).'/lucy-item-history-crawl';
        mkdir($workspace.'/state/crawler.lock');
        file_put_contents($workspace.'/state/crawler.lock/owner.json', "{}\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stop the crawler');
        $this->packager()->create($this->artifactDirectory, $this->outputDirectory);
    }

    public function test_flat_packaging_accepts_atomic_state_backups_only_when_the_primaries_are_absent(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory);
        $workspace = dirname($this->artifactDirectory).'/lucy-item-history-crawl';
        foreach (['config.json', 'queue.json', 'state/progress.json', 'state/status.json'] as $relative) {
            $this->assertTrue(rename($workspace.'/'.$relative, $workspace.'/'.$relative.'.bak'));
        }

        $result = $this->packager()->create($this->artifactDirectory, $this->outputDirectory);

        $this->assertFileExists($result['zip_path']);
    }

    public function test_flat_packaging_holds_the_node_crawler_lock_and_rechecks_completion_state(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory);
        $workspace = dirname($this->artifactDirectory).'/lucy-item-history-crawl';
        $observedLock = false;

        try {
            $this->packager()->create(
                $this->artifactDirectory,
                $this->outputDirectory,
                null,
                function (string $stage) use ($workspace, &$observedLock): void {
                    if ($stage !== 'archive') {
                        return;
                    }
                    $ownerPath = $workspace.'/state/crawler.lock/owner.json';
                    $owner = json_decode(file_get_contents($ownerPath), true, 64, JSON_THROW_ON_ERROR);
                    $this->assertSame('modern-allaclone.lucy-item-crawl-lock', $owner['schema']);
                    $observedLock = true;

                    $progressPath = $workspace.'/state/progress.json';
                    $progress = json_decode(file_get_contents($progressPath), true, 64, JSON_THROW_ON_ERROR);
                    $progress['refresh_item_list'] = true;
                    file_put_contents($progressPath, $this->itemHistoryPackageJson($progress));
                },
            );
            $this->fail('Crawler completion state changed during packaging should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('crawler progress changed while packaging', $exception->getMessage());
        }

        $this->assertTrue($observedLock);
        $this->assertDirectoryDoesNotExist($workspace.'/state/crawler.lock');
        $this->assertFileDoesNotExist($this->outputDirectory.'/item-history-package.json');
    }

    public function test_post_commit_crawler_lock_cleanup_failure_returns_success_with_a_recovery_warning(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory);
        $workspace = dirname($this->artifactDirectory).'/lucy-item-history-crawl';

        $result = $this->packager()->create(
            $this->artifactDirectory,
            $this->outputDirectory,
            null,
            function (string $stage) use ($workspace): void {
                if ($stage === 'committed') {
                    file_put_contents($workspace.'/state/crawler.lock/unexpected.txt', "retain fail-closed lock\n");
                }
            },
        );

        $this->assertFileExists($result['zip_path']);
        $this->assertFileExists($result['checksum_path']);
        $this->assertFileExists($result['descriptor_path']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('Package assets were published successfully', $result['warnings'][0]);
        $this->assertStringContainsString('crawler.lock', $result['warnings'][0]);
        $this->assertStringContainsString('manually remove', $result['warnings'][0]);
        $this->assertDirectoryExists($workspace.'/state/crawler.lock');
    }

    public function test_flat_packaging_requires_an_explicit_cross_platform_artifact_identity_alias(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory);
        $workspace = dirname($this->artifactDirectory).'/lucy-item-history-crawl';
        $windowsWorkspace = 'F:\\mounted\\private\\lucy-item-history-crawl';
        $windowsArtifactRoot = 'F:\\mounted\\private\\item-history';
        $this->createCompletedItemHistoryCrawlerWorkspace(
            $this->artifactDirectory,
            [20_542],
            $workspace,
            $windowsArtifactRoot,
            $windowsWorkspace,
        );

        try {
            $this->packager()->create($this->artifactDirectory, $this->temporaryDirectory.'/identity-mismatch');
            $this->fail('An implicit cross-platform path suffix should not be trusted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('artifact-root identity', $exception->getMessage());
        }

        try {
            $this->packager()->create(
                $this->artifactDirectory,
                $this->temporaryDirectory.'/artifact-identity-only',
                null,
                null,
                $workspace,
                $windowsArtifactRoot,
            );
            $this->fail('Artifact identity alone should not weaken crawler workspace provenance.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('selected workspace', $exception->getMessage());
        }

        $result = $this->packager()->create(
            $this->artifactDirectory,
            $this->temporaryDirectory.'/identity-aliases',
            null,
            null,
            $workspace,
            $windowsArtifactRoot,
            $windowsWorkspace,
        );
        $this->assertFileExists($result['zip_path']);
    }

    public function test_it_fails_early_with_actionable_guidance_when_php_memory_is_too_low(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory);
        $previous = ini_get('memory_limit');
        if (ini_set('memory_limit', '64M') === false) {
            $this->markTestSkipped('The PHP memory_limit cannot be changed in this environment.');
        }

        try {
            $this->packager()->create($this->artifactDirectory, $this->outputDirectory);
            $this->fail('A clearly insufficient PHP memory limit should be rejected before validation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('memory_limit', $exception->getMessage());
            $this->assertStringContainsString('php -d memory_limit=1G', $exception->getMessage());
        } finally {
            if (is_string($previous)) {
                ini_set('memory_limit', $previous);
            }
        }
    }

    public function test_it_refuses_to_overwrite_an_existing_release_asset_set(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->artifactDirectory);
        $this->packager()->create($this->artifactDirectory, $this->outputDirectory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to overwrite an existing release asset');
        $this->packager()->create($this->artifactDirectory, $this->outputDirectory);
    }

    private function packager(): ItemHistoryPackager
    {
        return new ItemHistoryPackager(new ItemHistoryMutationLock);
    }
}

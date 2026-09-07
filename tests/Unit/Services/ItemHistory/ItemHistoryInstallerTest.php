<?php

namespace Tests\Unit\Services\ItemHistory;

use App\Services\ItemHistory\ItemHistoryActivator;
use App\Services\ItemHistory\ItemHistoryArtifact;
use App\Services\ItemHistory\ItemHistoryInstaller;
use App\Services\ItemHistory\ItemHistoryMutationLock;
use App\Services\ItemHistory\ItemHistoryPackager;
use App\Services\ItemHistory\ItemHistoryPermissions;
use App\Services\ItemHistory\ItemHistoryRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CreatesItemHistoryPackageDataset;
use ZipArchive;

class ItemHistoryInstallerTest extends TestCase
{
    use CreatesItemHistoryPackageDataset;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not installed.');
        }
        $this->temporaryDirectory = sys_get_temp_dir().'/item-history-installer-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/source', 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory)) {
            $this->removeItemHistoryPackageTree($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function test_packager_and_installer_round_trip_is_idempotent_and_activation_is_explicit(): void
    {
        $paths = $this->createLegacyItemHistoryArtifacts(
            $this->temporaryDirectory.'/source',
            [20_542, 13_405],
        );
        foreach ([
            20_542 => '2026-09-06T21:12:54Z',
            13_405 => '2026-09-06T21:12:54.381Z',
        ] as $itemId => $generatedAt) {
            $artifact = json_decode(file_get_contents($paths[$itemId]), true, 64, JSON_THROW_ON_ERROR);
            $artifact['generated_at'] = $generatedAt;
            file_put_contents($paths[$itemId], $this->itemHistoryPackageJson($artifact));
        }
        $release = $this->packager()->create(
            $this->temporaryDirectory.'/source',
            $this->temporaryDirectory.'/release',
        );
        $descriptor = json_decode(
            file_get_contents($release['descriptor_path']),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('2026-09-06T21:12:54.381Z', $descriptor['latest_generated_at']);

        $first = $this->installer()->installFromFile(
            $release['zip_path'],
            $release['sha256'],
            $this->temporaryDirectory.'/installed',
            false,
            $this->smallLimits(),
        );

        $this->assertSame($release['dataset'], $first['dataset']);
        $this->assertFalse($first['reused']);
        $this->assertFalse($first['activated']);
        $this->assertNull($first['previous_dataset']);
        $this->assertSame(2, $first['stats']['item_count']);
        $this->assertSame(4, $first['stats']['revision_count']);
        $this->assertFileDoesNotExist($this->temporaryDirectory.'/installed/CURRENT');
        $this->assertFileExists($first['path'].'/manifest.json');

        $second = $this->installer()->installFromFile(
            $release['zip_path'],
            $release['sha256'],
            $this->temporaryDirectory.'/installed',
            true,
            $this->smallLimits(),
        );

        $this->assertTrue($second['reused']);
        $this->assertTrue($second['activated']);
        $this->assertNull($second['previous_dataset']);
        $this->assertSame(
            $release['dataset'],
            trim(file_get_contents($this->temporaryDirectory.'/installed/CURRENT')),
        );
        $this->assertSame([], glob($this->temporaryDirectory.'/installed/.installing-*') ?: []);
        $this->assertSame([], glob($this->temporaryDirectory.'/installed/.download-local-*') ?: []);
    }

    public function test_it_keeps_work_private_and_makes_new_and_reused_datasets_web_readable_on_posix(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permission bits are not meaningful on Windows.');
        }

        $this->createLegacyItemHistoryArtifacts(
            $this->temporaryDirectory.'/source',
            [20_542, 13_405],
        );
        $release = $this->packager()->create(
            $this->temporaryDirectory.'/source',
            $this->temporaryDirectory.'/release',
        );
        $installedRoot = $this->temporaryDirectory.'/installed-permissions';
        $privateWorkObserved = false;

        $first = $this->installer()->installFromFile(
            $release['zip_path'],
            $release['sha256'],
            $installedRoot,
            false,
            $this->smallLimits(),
            function (string $stage, array $context) use ($installedRoot, &$privateWorkObserved): void {
                if ($stage !== 'extract') {
                    return;
                }
                $this->assertSame($context['total'], $context['current']);
                $workDirectories = glob($installedRoot.'/.installing-*') ?: [];
                $this->assertCount(1, $workDirectories);
                $this->assertPosixMode(0700, $workDirectories[0]);
                $privateWorkObserved = true;
            },
        );

        $this->assertTrue($privateWorkObserved);
        $directories = [
            $installedRoot,
            $installedRoot.'/datasets',
            $first['path'],
            $first['path'].'/items',
            $first['path'].'/items/67',
            $first['path'].'/items/dc',
        ];
        $files = [
            $first['path'].'/manifest.json',
            $first['path'].'/COMPLETE.json',
            $first['path'].'/items/67/13405.json',
            $first['path'].'/items/dc/20542.json',
        ];
        foreach ($directories as $directory) {
            $this->assertPosixMode(0755, $directory);
            $this->assertTrue(chmod($directory, 0700));
        }
        foreach ($files as $file) {
            $this->assertPosixMode(0644, $file);
            $this->assertTrue(chmod($file, 0600));
        }

        $second = $this->installer()->installFromFile(
            $release['zip_path'],
            $release['sha256'],
            $installedRoot,
            true,
            $this->smallLimits(),
        );

        $this->assertTrue($second['reused']);
        $this->assertTrue($second['activated']);
        foreach ($directories as $directory) {
            $this->assertPosixMode(0755, $directory);
        }
        foreach ($files as $file) {
            $this->assertPosixMode(0644, $file);
        }
        foreach ([$installedRoot.'/CURRENT', $installedRoot.'/.activation.lock'] as $file) {
            $this->assertPosixMode(0644, $file);
        }
    }

    public function test_it_accepts_a_chmod_ineffective_mount_only_when_reported_modes_are_publicly_accessible(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('The POSIX compatibility branch is not used on Windows PHP.');
        }

        $this->createLegacyItemHistoryArtifacts(
            $this->temporaryDirectory.'/source',
            [20_542],
        );
        $release = $this->packager()->create(
            $this->temporaryDirectory.'/source',
            $this->temporaryDirectory.'/release',
        );
        $chmodCalls = 0;
        $permissions = new ItemHistoryPermissions(
            static function (string $path, int $mode) use (&$chmodCalls): bool {
                $chmodCalls++;

                return true;
            },
            static fn (string $path): int => 0777,
        );
        $installer = $this->installer($permissions);

        $installed = $installer->installFromFile(
            $release['zip_path'],
            $release['sha256'],
            $this->temporaryDirectory.'/installed-virtual-modes',
            true,
            $this->smallLimits(),
        );

        $this->assertGreaterThan(0, $chmodCalls);
        $this->assertTrue($installed['activated']);

        $restrictedPermissions = new ItemHistoryPermissions(
            static fn (string $path, int $mode): bool => true,
            static fn (string $path): int => is_dir($path) ? 0700 : 0600,
        );
        $restrictedInstaller = $this->installer($restrictedPermissions);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not retain its required permissions');

        $restrictedInstaller->installFromFile(
            $release['zip_path'],
            $release['sha256'],
            $this->temporaryDirectory.'/installed-restricted-modes',
            true,
            $this->smallLimits(),
        );
    }

    public function test_it_rejects_a_wrong_local_checksum_before_opening_the_zip(): void
    {
        $zipPath = $this->temporaryDirectory.'/not-a-package.zip';
        file_put_contents($zipPath, 'not a zip');

        try {
            $this->installer()->installFromFile(
                $zipPath,
                str_repeat('0', 64),
                $this->temporaryDirectory.'/installed',
                false,
                $this->smallLimits(),
            );
            $this->fail('A package with a wrong checksum should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('failed SHA-256 verification', $exception->getMessage());
        }

        $this->assertSame([], glob($this->temporaryDirectory.'/installed/.installing-*') ?: []);
        $this->assertSame([], glob($this->temporaryDirectory.'/installed/.download-local-*') ?: []);
    }

    public function test_it_round_trips_a_format_one_dataset_without_a_parser_version(): void
    {
        $itemId = 20_542;
        $artifact = $this->itemHistoryPackageArtifact($itemId);
        $artifact['format_version'] = ItemHistoryArtifact::FORMAT_VERSION;
        unset(
            $artifact['parser_format_version'],
            $artifact['capture_strategy'],
            $artifact['coverage'],
            $artifact['evidence'],
            $artifact['reconstruction'],
            $artifact['current_raw'],
        );
        $artifact['revisions'][0]['detail'] = [
            'name' => $artifact['latest_name'],
            'icon' => $artifact['latest_icon'],
            'snapshot_lines' => ['Initial item snapshot'],
        ];
        $artifact['revisions'][0]['capture_sha256'] = str_repeat('a', 64);
        foreach ($artifact['revisions'] as &$revision) {
            unset($revision['detail_fidelity'], $revision['history_capture_sha256s']);
        }
        unset($revision);

        $path = $this->temporaryDirectory.'/source/'.ItemHistoryArtifact::itemRelativePath($itemId);
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, $this->itemHistoryPackageJson($artifact));
        $this->createCompletedItemHistoryCrawlerWorkspace(
            $this->temporaryDirectory.'/source',
            [$itemId],
        );
        $release = $this->packager()->create(
            $this->temporaryDirectory.'/source',
            $this->temporaryDirectory.'/release-v1',
        );
        $descriptor = json_decode(
            file_get_contents($release['descriptor_path']),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame([1], $descriptor['artifact_format_versions']);
        $this->assertSame([], $descriptor['parser_format_versions']);

        $result = $this->installer()->installFromFile(
            $release['zip_path'],
            $release['sha256'],
            $this->temporaryDirectory.'/installed-v1',
            false,
            $this->smallLimits(),
        );

        $this->assertSame($release['dataset'], $result['dataset']);
        $this->assertSame(1, $result['stats']['item_count']);
    }

    public function test_it_round_trips_mixed_direct_and_reversible_parser_versions(): void
    {
        $sourceRoot = $this->temporaryDirectory.'/source-mixed';
        $directItemId = 13_405;
        $reversibleItemId = 20_542;
        $direct = $this->directItemHistoryPackageArtifact($directItemId);
        $direct['parser_format_version'] = ItemHistoryArtifact::DIRECT_DETAIL_PARSER_FORMAT_VERSION;
        foreach ([
            $directItemId => $direct,
            $reversibleItemId => $this->itemHistoryPackageArtifact($reversibleItemId),
        ] as $itemId => $artifact) {
            $path = $sourceRoot.'/'.ItemHistoryArtifact::itemRelativePath($itemId);
            mkdir(dirname($path), 0755, true);
            file_put_contents($path, $this->itemHistoryPackageJson($artifact));
        }
        $this->createCompletedItemHistoryCrawlerWorkspace(
            $sourceRoot,
            [$directItemId, $reversibleItemId],
        );
        $release = $this->packager()->create(
            $sourceRoot,
            $this->temporaryDirectory.'/release-mixed',
        );
        $descriptor = json_decode(
            file_get_contents($release['descriptor_path']),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame([1, 2], $descriptor['artifact_format_versions']);
        $this->assertSame([1, 2], $descriptor['parser_format_versions']);

        $result = $this->installer()->installFromFile(
            $release['zip_path'],
            $release['sha256'],
            $this->temporaryDirectory.'/installed-mixed',
            false,
            $this->smallLimits(),
        );
        $repository = new ItemHistoryRepository($result['path']);

        $this->assertSame(2, $result['stats']['item_count']);
        $this->assertSame(1, $repository->item($directItemId)['parser_format_version']);
        $this->assertSame(2, $repository->item($reversibleItemId)['parser_format_version']);
    }

    public function test_it_rejects_zip_path_traversal_without_writing_outside_staging(): void
    {
        $zipPath = $this->temporaryDirectory.'/traversal.zip';
        $dataset = str_repeat('b', 64);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL));
        $zip->addFromString('../escaped.txt', 'unsafe');
        $zip->addFromString('package.json', '{}');
        $zip->addFromString("dataset/{$dataset}/manifest.json", '{}');
        $zip->addFromString("dataset/{$dataset}/COMPLETE.json", '{}');
        $zip->addFromString("dataset/{$dataset}/items/dc/20542.json", '{}');
        $this->assertTrue($zip->close());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe path');
        try {
            $this->installer()->installFromFile(
                $zipPath,
                hash_file('sha256', $zipPath),
                $this->temporaryDirectory.'/installed',
                false,
                $this->smallLimits(),
            );
        } finally {
            $this->assertFileDoesNotExist($this->temporaryDirectory.'/escaped.txt');
        }
    }

    public function test_it_rejects_a_windows_device_archive_name_in_package_metadata(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->temporaryDirectory.'/source');
        $release = $this->packager()->create(
            $this->temporaryDirectory.'/source',
            $this->temporaryDirectory.'/release',
        );
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($release['zip_path']));
        $package = json_decode($zip->getFromName('package.json'), true, 64, JSON_THROW_ON_ERROR);
        $package['archive']['name'] = 'CON.payload.zip';
        $this->assertTrue($zip->addFromString('package.json', $this->itemHistoryPackageJson($package)));
        $this->assertTrue($zip->close());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('archive name is invalid');
        $this->installer()->installFromFile(
            $release['zip_path'],
            hash_file('sha256', $release['zip_path']),
            $this->temporaryDirectory.'/installed-reserved-name',
            false,
            $this->smallLimits(),
        );
    }

    public function test_it_rejects_an_item_that_no_longer_matches_the_signed_manifest_inventory(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->temporaryDirectory.'/source');
        $release = $this->packager()->create(
            $this->temporaryDirectory.'/source',
            $this->temporaryDirectory.'/release',
        );

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($release['zip_path']));
        $itemName = "dataset/{$release['dataset']}/items/dc/20542.json";
        $this->assertTrue($zip->addFromString($itemName, "{}\n"));
        $this->assertTrue($zip->close());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match its manifest digest');
        $this->installer()->installFromFile(
            $release['zip_path'],
            hash_file('sha256', $release['zip_path']),
            $this->temporaryDirectory.'/installed',
            false,
            $this->smallLimits(),
        );
    }

    public function test_it_revalidates_staged_bytes_under_the_mutation_lock_before_promotion(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->temporaryDirectory.'/source');
        $release = $this->packager()->create(
            $this->temporaryDirectory.'/source',
            $this->temporaryDirectory.'/release',
        );
        $installed = $this->temporaryDirectory.'/installed';
        $mutated = false;

        try {
            $this->installer()->installFromFile(
                $release['zip_path'],
                $release['sha256'],
                $installed,
                false,
                $this->smallLimits(),
                function (string $stage, array $progress) use ($installed, &$mutated): void {
                    if ($stage !== 'validate' || $progress['current'] !== $progress['total']) {
                        return;
                    }

                    $matches = glob($installed.'/.installing-*/dataset/*/items/dc/20542.json') ?: [];
                    $this->assertCount(1, $matches);
                    file_put_contents($matches[0], "{}\n");
                    $mutated = true;
                },
            );
            $this->fail('A staged dataset changed after validation should not be promoted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('changed after validation', $exception->getMessage());
        }

        $this->assertTrue($mutated);
        $this->assertSame([], glob($installed.'/datasets/*') ?: []);
        $this->assertSame([], glob($installed.'/.installing-*') ?: []);
        $this->assertSame([], glob($installed.'/.download-local-*') ?: []);
    }

    /** @return array<string, int> */
    private function smallLimits(): array
    {
        return [
            'max_download_bytes' => 10_000_000,
            'max_unpacked_bytes' => 10_000_000,
            'max_files' => 100,
            'connect_timeout' => 5,
            'download_timeout' => 30,
        ];
    }

    private function installer(
        ?ItemHistoryPermissions $permissions = null,
    ): ItemHistoryInstaller {
        return new ItemHistoryInstaller(
            new ItemHistoryActivator($permissions),
            new ItemHistoryMutationLock,
            $permissions,
        );
    }

    private function packager(): ItemHistoryPackager
    {
        return new ItemHistoryPackager(new ItemHistoryMutationLock);
    }

    private function assertPosixMode(int $expected, string $path): void
    {
        clearstatcache(true, $path);
        $mode = fileperms($path);
        $this->assertIsInt($mode);
        $this->assertSame($expected, $mode & 0777, $path);
    }
}

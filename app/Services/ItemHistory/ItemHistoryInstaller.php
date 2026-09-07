<?php

namespace App\Services\ItemHistory;

use App\Services\SpellHistory\SafePath;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

final class ItemHistoryInstaller
{
    private const MAX_RELEASE_METADATA_BYTES = 4_194_304;

    private const COPY_CHUNK_BYTES = 1_048_576;

    private const DISK_HEADROOM_BYTES = 268_435_456;

    private const DEFAULT_MAX_DOWNLOAD_BYTES = 1_073_741_824;

    private const DEFAULT_MAX_UNPACKED_BYTES = 3_221_225_472;

    private const DEFAULT_MAX_FILES = 200_000;

    private const MAX_DOWNLOAD_CEILING = 2_147_483_648;

    private const MAX_UNPACKED_CEILING = 17_179_869_184;

    private const MAX_FILES_CEILING = 1_000_000;

    private const DATASET_HASH_DOMAIN = "modern-allaclone.item-history-dataset-v1\0";

    private readonly ItemHistoryPermissions $permissions;

    public function __construct(
        private readonly ItemHistoryActivator $activator,
        private readonly ItemHistoryMutationLock $mutationLock,
        ?ItemHistoryPermissions $permissions = null,
    ) {
        $this->permissions = $permissions ?? new ItemHistoryPermissions;
    }

    /**
     * @param  array{max_download_bytes?: int, max_unpacked_bytes?: int, max_files?: int, connect_timeout?: int, download_timeout?: int}  $limits
     * @param  callable(string, array<string, mixed>): void|null  $progress
     * @return array{dataset: string, reused: bool, activated: bool, previous_dataset: ?string, path: string, stats: array<string, int>}
     */
    public function installFromFile(
        string $file,
        string $sha256,
        string $artifactRoot,
        bool $activate = true,
        array $limits = [],
        ?callable $progress = null,
    ): array {
        $expectedHash = $this->normalizeSha256($sha256, 'Expected archive SHA-256');
        $resolvedLimits = $this->resolveLimits($limits);
        $root = $this->prepareArtifactRoot($artifactRoot);
        $snapshotDirectory = $root.'/.download-local-'.bin2hex(random_bytes(16));
        if (! mkdir($snapshotDirectory, 0700)) {
            throw new RuntimeException('Unable to create the item history local-package staging directory.');
        }

        $installed = false;
        try {
            $snapshot = $snapshotDirectory.'/package.zip';
            $this->snapshotLocalArchive(
                $file,
                $snapshot,
                $expectedHash,
                $resolvedLimits['max_download_bytes'],
            );

            $result = $this->installArchive(
                $snapshot,
                $expectedHash,
                $root,
                $activate,
                $resolvedLimits,
                null,
                $progress,
            );
            $installed = true;

            return $result;
        } finally {
            $this->cleanupWorkDirectory($snapshotDirectory, $root, '.download-local-', $installed);
        }
    }

    /**
     * @param  array{max_download_bytes?: int, max_unpacked_bytes?: int, max_files?: int, connect_timeout?: int, download_timeout?: int}  $limits
     * @param  callable(string, array<string, mixed>): void|null  $progress
     * @return array{dataset: string, reused: bool, activated: bool, previous_dataset: ?string, path: string, stats: array<string, int>}
     */
    public function installFromRelease(
        string $tag,
        string $repository,
        string $pinnedSha256,
        string $artifactRoot,
        bool $activate = true,
        array $limits = [],
        ?callable $progress = null,
    ): array {
        $repository = $this->validateRepository($repository);
        $expectedHash = $this->normalizeSha256($pinnedSha256, 'Pinned release archive SHA-256');
        $tag = trim($tag);
        if ($tag === '' || strlen($tag) > 200 || preg_match('/[\x00-\x1f\x7f]/', $tag) === 1) {
            throw new RuntimeException('GitHub release tag is invalid.');
        }

        $resolvedLimits = $this->resolveLimits($limits);
        $root = $this->prepareArtifactRoot($artifactRoot);
        $downloadDirectory = $root.'/.download-'.bin2hex(random_bytes(16));
        if (! mkdir($downloadDirectory, 0700)) {
            throw new RuntimeException('Unable to create the item history download directory.');
        }

        $installed = false;
        try {
            $release = $this->githubRelease($repository, $tag, $resolvedLimits);
            if (($release['tag_name'] ?? null) !== $tag) {
                throw new RuntimeException('GitHub returned a release for a different tag.');
            }

            $descriptorAsset = $this->githubAsset($release, ItemHistoryDataset::RELEASE_DESCRIPTOR);
            $descriptorDigest = $this->githubDigest($descriptorAsset, 'release descriptor');
            $descriptorPath = $downloadDirectory.'/'.ItemHistoryDataset::RELEASE_DESCRIPTOR;
            $this->downloadGithubAsset(
                $descriptorAsset,
                $repository,
                $descriptorPath,
                min(ItemHistoryDataset::MAX_DESCRIPTOR_BYTES, $resolvedLimits['max_download_bytes']),
                $resolvedLimits,
            );
            $this->assertFileHash($descriptorPath, $descriptorDigest, 'Downloaded release descriptor');
            $descriptor = $this->decodeJsonFile(
                $descriptorPath,
                ItemHistoryDataset::MAX_DESCRIPTOR_BYTES,
                'item history release descriptor',
            );
            $package = $this->validatePackageDescriptor($descriptor, true);

            $archiveAsset = $this->githubAsset($release, $package['archive']['name']);
            $archiveDigest = $this->githubDigest($archiveAsset, 'release archive');
            if (! hash_equals($package['archive']['sha256'], $archiveDigest)) {
                throw new RuntimeException('The GitHub archive digest does not match the release descriptor.');
            }
            if (! hash_equals($expectedHash, $archiveDigest)) {
                throw new RuntimeException('The GitHub archive digest does not match the pinned release checksum.');
            }
            if (($archiveAsset['size'] ?? null) !== $package['archive']['bytes']) {
                throw new RuntimeException('The GitHub archive size does not match the release descriptor.');
            }

            $archivePath = $downloadDirectory.'/'.$package['archive']['name'];
            if ($progress !== null) {
                $progress('download', [
                    'asset' => $package['archive']['name'],
                    'bytes' => $package['archive']['bytes'],
                ]);
            }
            $this->downloadGithubAsset(
                $archiveAsset,
                $repository,
                $archivePath,
                $resolvedLimits['max_download_bytes'],
                $resolvedLimits,
            );

            $result = $this->installArchive(
                $archivePath,
                $archiveDigest,
                $root,
                $activate,
                $resolvedLimits,
                $descriptor,
                $progress,
            );
            $installed = true;

            return $result;
        } finally {
            $this->cleanupWorkDirectory($downloadDirectory, $root, '.download-', $installed);
        }
    }

    /**
     * @param  array{max_download_bytes: int, max_unpacked_bytes: int, max_files: int, connect_timeout: int, download_timeout: int}  $limits
     * @param  array<string, mixed>|null  $externalDescriptor
     * @param  callable(string, array<string, mixed>): void|null  $progress
     * @return array{dataset: string, reused: bool, activated: bool, previous_dataset: ?string, path: string, stats: array<string, int>}
     */
    private function installArchive(
        string $archivePath,
        string $expectedSha256,
        string $artifactDirectory,
        bool $activate,
        array $limits,
        ?array $externalDescriptor,
        ?callable $progress,
    ): array {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to install item history packages.');
        }

        $archive = $this->safeLocalArchive($archivePath);
        $archiveBytes = filesize($archive);
        if ($archiveBytes === false || $archiveBytes < 1 || $archiveBytes > $limits['max_download_bytes']) {
            throw new RuntimeException('The item history ZIP exceeds the configured download limit.');
        }
        $this->assertFileHash($archive, $expectedSha256, 'Item history ZIP');

        $artifactRoot = $this->prepareArtifactRoot($artifactDirectory);
        $stageDirectory = $artifactRoot.'/.installing-'.bin2hex(random_bytes(16));

        $committed = false;
        try {
            $zip = new ZipArchive;
            $opened = $zip->open($archive, ZipArchive::RDONLY);
            if ($opened !== true) {
                throw new RuntimeException("Unable to open the item history ZIP (code {$opened}).");
            }

            try {
                $plan = $this->preflightArchive($zip, $limits);
                $freeBytes = disk_free_space($artifactRoot);
                if ((is_float($freeBytes) || is_int($freeBytes))
                    && $freeBytes < $plan['unpacked_bytes'] + self::DISK_HEADROOM_BYTES) {
                    throw new RuntimeException('There is not enough free disk space to stage the item history dataset.');
                }

                if (! mkdir($stageDirectory, 0700)) {
                    throw new RuntimeException('Unable to create the item history installation staging directory.');
                }
                $this->extractArchive($zip, $plan['file_count'], $stageDirectory, $limits, $progress);
            } finally {
                $zip->close();
            }

            $insideDescriptor = $this->decodeJsonFile(
                $stageDirectory.'/package.json',
                ItemHistoryDataset::MAX_DESCRIPTOR_BYTES,
                'packaged item history descriptor',
            );
            $insidePackage = $this->validatePackageDescriptor($insideDescriptor, false);
            if ($insidePackage['dataset'] !== $plan['dataset']) {
                throw new RuntimeException('The packaged descriptor does not match the ZIP dataset directory.');
            }
            if ($insidePackage['archive']['sha256'] !== null
                && ! hash_equals($insidePackage['archive']['sha256'], $expectedSha256)) {
                throw new RuntimeException('The packaged descriptor archive hash is inconsistent.');
            }
            if ($insidePackage['archive']['bytes'] !== null
                && $insidePackage['archive']['bytes'] !== $archiveBytes) {
                throw new RuntimeException('The packaged descriptor archive size is inconsistent.');
            }

            if ($externalDescriptor !== null) {
                $outsidePackage = $this->validatePackageDescriptor($externalDescriptor, true);
                $this->assertSamePackageIdentity($insidePackage, $outsidePackage);
                if (! hash_equals($outsidePackage['archive']['sha256'], $expectedSha256)
                    || $outsidePackage['archive']['bytes'] !== $archiveBytes) {
                    throw new RuntimeException('The release descriptor does not match the verified archive bytes.');
                }
            }

            $stagedDataset = $stageDirectory.'/dataset/'.$plan['dataset'];
            $validation = $this->validateDataset($stagedDataset, $insidePackage, $progress);
            if ($validation['stats']['unpacked_bytes'] !== $plan['dataset_unpacked_bytes']) {
                throw new RuntimeException('The ZIP dataset byte count does not match its verified descriptor.');
            }

            $installed = $this->mutationLock->exclusive(
                $artifactRoot,
                function () use (
                    $activate,
                    $artifactRoot,
                    $limits,
                    $plan,
                    $stagedDataset,
                    $insidePackage,
                ): array {
                    $inventory = $this->revalidateDatasetInventory(
                        $stagedDataset,
                        $plan['dataset'],
                        $limits['max_files'],
                        $insidePackage,
                    );
                    $datasetsPath = $artifactRoot.'/datasets';
                    if (is_link($datasetsPath)) {
                        throw new RuntimeException('Item history datasets directory cannot be a symbolic link.');
                    }
                    $datasetsRoot = SafePath::existingDirectory(
                        $datasetsPath,
                        'Item history datasets directory',
                    );
                    SafePath::assertContained(
                        $datasetsRoot,
                        $artifactRoot,
                        'Item history datasets directory',
                    );
                    $finalDirectory = $datasetsRoot.'/'.$plan['dataset'];
                    $reused = false;

                    if (is_link($finalDirectory)) {
                        throw new RuntimeException('An installed item history dataset cannot be a symbolic link.');
                    }
                    if (file_exists($finalDirectory)) {
                        if (! is_dir($finalDirectory)
                            || ! $this->datasetsMatch(
                                $stagedDataset,
                                $finalDirectory,
                                $inventory,
                                $limits['max_files'],
                            )) {
                            throw new RuntimeException(
                                'A dataset with this key already exists but its contents differ from the verified package.'
                            );
                        }
                        $this->normalizeInstalledDatasetPermissions($finalDirectory, $inventory);
                        $reused = true;
                    } else {
                        $this->normalizeInstalledDatasetPermissions($stagedDataset, $inventory);
                        if (! rename($stagedDataset, $finalDirectory)) {
                            throw new RuntimeException('Unable to atomically install the staged item history dataset.');
                        }
                    }

                    return [
                        'path' => $finalDirectory,
                        'reused' => $reused,
                        'previous' => $activate
                            ? $this->activator->activate($artifactRoot, $plan['dataset'])
                            : null,
                    ];
                },
            );

            $result = [
                'dataset' => $plan['dataset'],
                'reused' => $installed['reused'],
                'activated' => $activate,
                'previous_dataset' => $installed['previous'],
                'path' => SafePath::normalize($installed['path']),
                'stats' => $validation['stats'],
            ];
            $committed = true;

            return $result;
        } finally {
            if (is_dir($stageDirectory) || is_link($stageDirectory)) {
                $this->cleanupWorkDirectory($stageDirectory, $artifactRoot, '.installing-', $committed);
            }
        }
    }

    /**
     * @param  array{max_download_bytes: int, max_unpacked_bytes: int, max_files: int, connect_timeout: int, download_timeout: int}  $limits
     * @return array{dataset: string, file_count: int, unpacked_bytes: int, dataset_unpacked_bytes: int}
     */
    private function preflightArchive(ZipArchive $zip, array $limits): array
    {
        if ($zip->numFiles < 4 || $zip->numFiles > $limits['max_files'] + 260) {
            throw new RuntimeException('The item history ZIP has an invalid entry count.');
        }

        $seen = [];
        $datasetKeys = [];
        $unpackedBytes = 0;
        $datasetUnpackedBytes = 0;
        $fileCount = 0;
        $hasPackage = false;
        $hasManifest = false;
        $hasCompletion = false;
        $itemCount = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (! is_array($stat) || ! is_string($stat['name'] ?? null)) {
                throw new RuntimeException('The item history ZIP contains an unreadable entry.');
            }
            $name = $stat['name'];
            $directory = str_ends_with($name, '/');
            $this->assertSafeArchiveName($name, $directory);
            $comparison = strtolower($name);
            if (isset($seen[$comparison])) {
                throw new RuntimeException("The item history ZIP contains a duplicate entry: {$name}");
            }
            $seen[$comparison] = true;
            $this->assertRegularZipEntry($zip, $index, $directory, $name);

            $size = $stat['size'] ?? null;
            if (! is_int($size) || $size < 0 || ($directory && $size !== 0)) {
                throw new RuntimeException("The item history ZIP entry has an invalid size: {$name}");
            }
            if (is_int($stat['encryption_method'] ?? null)
                && $stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw new RuntimeException("Encrypted ZIP entries are not supported: {$name}");
            }

            if ($directory) {
                if (! $this->allowedArchiveDirectory($name)) {
                    throw new RuntimeException("Unexpected directory in item history ZIP: {$name}");
                }
                if (preg_match('#^dataset/([a-f0-9]{64})/#D', $name, $match) === 1) {
                    $datasetKeys[$match[1]] = true;
                }

                continue;
            }

            $fileCount++;
            if ($fileCount > $limits['max_files'] + 1) {
                throw new RuntimeException('The item history ZIP exceeds the configured file-count limit.');
            }
            if ($size > $limits['max_unpacked_bytes'] - $unpackedBytes) {
                throw new RuntimeException('The item history ZIP exceeds the configured unpacked-byte limit.');
            }
            $unpackedBytes += $size;

            if ($name === 'package.json') {
                $hasPackage = true;
                $this->assertEntrySize($size, ItemHistoryDataset::MAX_DESCRIPTOR_BYTES, 'package descriptor');
            } elseif (preg_match('#^dataset/([a-f0-9]{64})/manifest\.json$#D', $name, $match) === 1) {
                $datasetKeys[$match[1]] = true;
                $hasManifest = true;
                $datasetUnpackedBytes += $size;
                $this->assertEntrySize($size, ItemHistoryDataset::MAX_MANIFEST_BYTES, 'manifest');
            } elseif (preg_match('#^dataset/([a-f0-9]{64})/COMPLETE\.json$#D', $name, $match) === 1) {
                $datasetKeys[$match[1]] = true;
                $hasCompletion = true;
                $datasetUnpackedBytes += $size;
                $this->assertEntrySize($size, ItemHistoryDataset::MAX_COMPLETION_BYTES, 'completion marker');
            } elseif (preg_match('#^dataset/([a-f0-9]{64})/items/([a-f0-9]{2})/([1-9][0-9]*)\.json$#D', $name, $match) === 1) {
                $datasetKeys[$match[1]] = true;
                $itemId = filter_var($match[3], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
                ]);
                if ($itemId === false || $match[2] !== substr(hash('sha256', (string) $itemId), 0, 2)) {
                    throw new RuntimeException("Item artifact is in the wrong shard directory: {$name}");
                }
                $itemCount++;
                $datasetUnpackedBytes += $size;
                $this->assertEntrySize($size, ItemHistoryArtifact::MAX_ITEM_BYTES, 'item artifact');
            } else {
                throw new RuntimeException("Unexpected file in item history ZIP: {$name}");
            }

        }

        $keys = array_keys($datasetKeys);
        if (! $hasPackage || ! $hasManifest || ! $hasCompletion || $itemCount < 1 || count($keys) !== 1) {
            throw new RuntimeException('The item history ZIP does not contain exactly one complete dataset.');
        }

        return [
            'dataset' => $keys[0],
            'file_count' => $fileCount,
            'unpacked_bytes' => $unpackedBytes,
            'dataset_unpacked_bytes' => $datasetUnpackedBytes,
        ];
    }

    private function assertSafeArchiveName(string $name, bool $directory): void
    {
        if ($name === '' || strlen($name) > 512 || str_contains($name, "\0") || str_contains($name, '\\')
            || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            throw new RuntimeException('The item history ZIP contains an unsafe path.');
        }

        $trimmed = $directory ? substr($name, 0, -1) : $name;
        $segments = explode('/', $trimmed);
        if ($trimmed === '' || in_array('', $segments, true)
            || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new RuntimeException("The item history ZIP contains an unsafe path: {$name}");
        }
    }

    private function allowedArchiveDirectory(string $name): bool
    {
        return $name === 'dataset/'
            || preg_match('#^dataset/[a-f0-9]{64}/$#D', $name) === 1
            || preg_match('#^dataset/[a-f0-9]{64}/items/$#D', $name) === 1
            || preg_match('#^dataset/[a-f0-9]{64}/items/[a-f0-9]{2}/$#D', $name) === 1;
    }

    private function assertRegularZipEntry(ZipArchive $zip, int $index, bool $directory, string $name): void
    {
        if (! method_exists($zip, 'getExternalAttributesIndex')) {
            return;
        }

        $operatingSystem = 0;
        $attributes = 0;
        if (! $zip->getExternalAttributesIndex(
            $index,
            $operatingSystem,
            $attributes,
            ZipArchive::FL_UNCHANGED,
        )) {
            throw new RuntimeException("Unable to inspect ZIP entry attributes: {$name}");
        }

        if ($operatingSystem !== 3) {
            return;
        }
        $type = (($attributes >> 16) & 0xFFFF) & 0170000;
        $expected = $directory ? 0040000 : 0100000;
        if ($type !== 0 && $type !== $expected) {
            throw new RuntimeException("The item history ZIP contains a non-regular entry: {$name}");
        }
    }

    private function assertEntrySize(int $size, int $maximum, string $label): void
    {
        if ($size < 2 || $size > $maximum) {
            throw new RuntimeException("The packaged {$label} has an invalid size.");
        }
    }

    /**
     * @param  array{max_download_bytes: int, max_unpacked_bytes: int, max_files: int, connect_timeout: int, download_timeout: int}  $limits
     * @param  callable(string, array<string, mixed>): void|null  $progress
     */
    private function extractArchive(
        ZipArchive $zip,
        int $expectedFiles,
        string $stageDirectory,
        array $limits,
        ?callable $progress,
    ): void {
        $writtenTotal = 0;
        $extracted = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (! is_array($stat) || ! is_string($stat['name'] ?? null)
                || ! is_int($stat['size'] ?? null)) {
                throw new RuntimeException('The item history ZIP changed while preparing extraction.');
            }
            $name = $stat['name'];
            if (str_ends_with($name, '/')) {
                continue;
            }
            $size = $stat['size'];
            $extracted++;
            if ($extracted > $expectedFiles) {
                throw new RuntimeException('The item history ZIP file count changed during extraction.');
            }

            $destination = $stageDirectory.'/'.$name;
            $parent = dirname($destination);
            if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
                throw new RuntimeException("Unable to create a staging directory for {$name}.");
            }
            if (is_link($parent)) {
                throw new RuntimeException('An item history staging directory became unsafe.');
            }
            SafePath::assertContained($parent, $stageDirectory, 'Item history staging directory');

            $source = $zip->getStream($name);
            if ($source === false) {
                throw new RuntimeException("Unable to read ZIP entry {$name}.");
            }
            $target = fopen($destination, 'x+b');
            if ($target === false) {
                fclose($source);
                throw new RuntimeException("Unable to create staged file {$name}.");
            }

            $written = 0;
            try {
                while (! feof($source)) {
                    $chunk = fread($source, self::COPY_CHUNK_BYTES);
                    if ($chunk === false) {
                        throw new RuntimeException("Unable to read ZIP entry {$name}.");
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    $chunkBytes = strlen($chunk);
                    if ($chunkBytes > $size - $written
                        || $chunkBytes > $limits['max_unpacked_bytes'] - $writtenTotal) {
                        throw new RuntimeException('A ZIP entry expanded beyond its declared or configured limit.');
                    }
                    $this->writeAll($target, $chunk, $name);
                    $written += $chunkBytes;
                    $writtenTotal += $chunkBytes;
                }
                if ($written !== $size || ! fflush($target)) {
                    throw new RuntimeException("ZIP entry size changed while extracting {$name}.");
                }
            } finally {
                fclose($source);
                fclose($target);
            }

            if ($progress !== null && ($extracted === $expectedFiles || $extracted % 5_000 === 0)) {
                $progress('extract', [
                    'current' => $extracted,
                    'total' => $expectedFiles,
                    'bytes' => $writtenTotal,
                ]);
            }
        }
        if ($extracted !== $expectedFiles) {
            throw new RuntimeException('The item history ZIP file count changed during extraction.');
        }
    }

    /** @param resource $target */
    private function writeAll($target, string $bytes, string $name): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($target, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException("Unable to write staged file {$name}.");
            }
            $offset += $written;
        }
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @return array<string, mixed>
     */
    private function validatePackageDescriptor(array $descriptor, bool $external): array
    {
        $this->assertExactKeys($descriptor, [
            'schema',
            'package_version',
            'dataset',
            'dataset_format_version',
            'artifact_schema',
            'artifact_format_versions',
            'parser_format_versions',
            'first_observed_at',
            'last_observed_at',
            'latest_generated_at',
            'stats',
            'archive',
        ], 'item history package descriptor');

        $dataset = $descriptor['dataset'];
        $stats = $descriptor['stats'];
        $archive = $descriptor['archive'];
        if ($descriptor['schema'] !== ItemHistoryDataset::PACKAGE_SCHEMA
            || $descriptor['package_version'] !== ItemHistoryDataset::PACKAGE_VERSION
            || ! is_string($dataset)
            || preg_match(ItemHistoryDataset::DATASET_KEY_PATTERN, $dataset) !== 1
            || $descriptor['dataset_format_version'] !== ItemHistoryDataset::DATASET_FORMAT_VERSION
            || $descriptor['artifact_schema'] !== ItemHistoryArtifact::SCHEMA
            || ! $this->validArtifactVersions($descriptor['artifact_format_versions'])
            || ! $this->validParserVersions($descriptor['parser_format_versions'])
            || ! is_string($descriptor['first_observed_at'])
            || ! $this->validObservedTimestamp($descriptor['first_observed_at'])
            || ! is_string($descriptor['last_observed_at'])
            || ! $this->validObservedTimestamp($descriptor['last_observed_at'])
            || $descriptor['last_observed_at'] < $descriptor['first_observed_at']
            || ! is_string($descriptor['latest_generated_at'])
            || ! $this->validGeneratedTimestamp($descriptor['latest_generated_at'])
            || ! is_array($stats) || array_is_list($stats)
            || ! is_array($archive) || array_is_list($archive)) {
            throw new RuntimeException('The item history package descriptor is incompatible or incomplete.');
        }

        $this->assertExactKeys($stats, [
            'item_count', 'revision_count', 'artifact_bytes', 'file_count', 'unpacked_bytes',
        ], 'item history package statistics');
        foreach (['item_count', 'revision_count', 'artifact_bytes', 'file_count', 'unpacked_bytes'] as $field) {
            if (! is_int($stats[$field]) || $stats[$field] < 1) {
                throw new RuntimeException("The item history package statistic {$field} is invalid.");
            }
        }
        if ($stats['revision_count'] < $stats['item_count']
            || $stats['file_count'] !== $stats['item_count'] + 2
            || $stats['unpacked_bytes'] <= $stats['artifact_bytes']) {
            throw new RuntimeException('The item history package file, revision, or byte counts are inconsistent.');
        }

        $this->assertExactKeys($archive, ['name', 'sha256', 'bytes'], 'item history package archive');
        $archiveName = $archive['name'];
        if (! is_string($archiveName)
            || ! ItemHistoryDataset::isSafeArchiveName($archiveName)) {
            throw new RuntimeException('The item history package archive name is invalid.');
        }
        $archiveHash = $archive['sha256'];
        $archiveBytes = $archive['bytes'];
        if ($external) {
            $archiveHash = $this->normalizeSha256($archiveHash, 'Release archive SHA-256');
            if (! is_int($archiveBytes) || $archiveBytes < 1) {
                throw new RuntimeException('The release archive byte count is invalid.');
            }
        } else {
            if ($archiveHash !== null) {
                $archiveHash = $this->normalizeSha256($archiveHash, 'Packaged archive SHA-256');
            }
            if ($archiveBytes !== null && (! is_int($archiveBytes) || $archiveBytes < 1)) {
                throw new RuntimeException('The packaged archive byte count is invalid.');
            }
        }

        return [
            'dataset' => $dataset,
            'dataset_format_version' => ItemHistoryDataset::DATASET_FORMAT_VERSION,
            'artifact_schema' => ItemHistoryArtifact::SCHEMA,
            'artifact_format_versions' => $descriptor['artifact_format_versions'],
            'parser_format_versions' => $descriptor['parser_format_versions'],
            'first_observed_at' => $descriptor['first_observed_at'],
            'last_observed_at' => $descriptor['last_observed_at'],
            'latest_generated_at' => $descriptor['latest_generated_at'],
            'stats' => [
                'item_count' => $stats['item_count'],
                'revision_count' => $stats['revision_count'],
                'artifact_bytes' => $stats['artifact_bytes'],
                'file_count' => $stats['file_count'],
                'unpacked_bytes' => $stats['unpacked_bytes'],
            ],
            'archive' => [
                'name' => $archiveName,
                'sha256' => $archiveHash,
                'bytes' => $archiveBytes,
            ],
        ];
    }

    /** @param array<string, mixed> $inside @param array<string, mixed> $outside */
    private function assertSamePackageIdentity(array $inside, array $outside): void
    {
        foreach ([
            'dataset',
            'dataset_format_version',
            'artifact_schema',
            'artifact_format_versions',
            'parser_format_versions',
            'first_observed_at',
            'last_observed_at',
            'latest_generated_at',
            'stats',
        ] as $field) {
            if ($inside[$field] !== $outside[$field]) {
                throw new RuntimeException("The internal and release package descriptors disagree about {$field}.");
            }
        }
        if ($inside['archive']['name'] !== $outside['archive']['name']) {
            throw new RuntimeException('The internal and release package descriptors name different archives.');
        }
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  callable(string, array<string, mixed>): void|null  $progress
     * @return array{stats: array<string, int>}
     */
    private function validateDataset(string $datasetDirectory, array $package, ?callable $progress): array
    {
        $datasetRoot = SafePath::existingDirectory($datasetDirectory, 'Staged item history dataset');
        if (is_link($datasetDirectory)) {
            throw new RuntimeException('The staged item history dataset cannot be a symbolic link.');
        }

        $dataset = $package['dataset'];
        $manifestPath = $datasetRoot.'/manifest.json';
        $manifest = $this->decodeJsonFile(
            $manifestPath,
            ItemHistoryDataset::MAX_MANIFEST_BYTES,
            'staged item history manifest',
        );
        $this->validateManifestHeader($manifest, $dataset);
        $manifestStats = $manifest['stats'];
        foreach (['item_count', 'revision_count', 'artifact_bytes', 'file_count'] as $field) {
            if ($manifestStats[$field] !== $package['stats'][$field]) {
                throw new RuntimeException("The package {$field} does not match the manifest.");
            }
        }
        foreach ([
            'artifact_format_versions',
            'parser_format_versions',
            'first_observed_at',
            'last_observed_at',
            'latest_generated_at',
        ] as $field) {
            if ($manifest[$field] !== $package[$field]) {
                throw new RuntimeException("The package {$field} does not match the manifest.");
            }
        }

        $manifestFiles = &$manifest['files'];
        $this->validateManifestFiles($manifestFiles, $manifestStats['item_count']);
        $computedDataset = $this->datasetKey(
            $manifest['artifact_format_versions'],
            $manifest['parser_format_versions'],
            $manifestFiles,
        );
        if (! hash_equals($dataset, $computedDataset)) {
            throw new RuntimeException('The item history dataset key does not match its manifest file inventory.');
        }

        $completionPath = $datasetRoot.'/COMPLETE.json';
        $completion = $this->decodeJsonFile(
            $completionPath,
            ItemHistoryDataset::MAX_COMPLETION_BYTES,
            'staged item history completion marker',
        );
        $this->validateCompletion($completion, $dataset);
        $manifestBytes = filesize($manifestPath);
        $manifestHash = hash_file('sha256', $manifestPath);
        if ($manifestBytes === false || $manifestHash === false
            || $completion['manifest_bytes'] !== $manifestBytes
            || ! hash_equals($manifestHash, $completion['manifest_sha256'])) {
            throw new RuntimeException('The staged item history completion marker does not match its manifest.');
        }
        foreach (['item_count', 'revision_count', 'artifact_bytes', 'file_count'] as $field) {
            if ($completion[$field] !== $manifestStats[$field]) {
                throw new RuntimeException("The completion marker {$field} does not match the manifest.");
            }
        }

        $this->assertItemInventory($datasetRoot, $manifestFiles, false);

        $repository = new ItemHistoryRepository($datasetRoot);
        $revisionCount = 0;
        $artifactBytes = 0;
        $firstObserved = null;
        $lastObserved = null;
        $latestGenerated = null;
        $latestGeneratedInstant = null;
        $artifactVersions = [];
        $parserVersions = [];
        foreach ($manifestFiles as $index => $entry) {
            $path = $datasetRoot.'/'.$entry['path'];
            $itemId = $this->itemIdFromManifestPath($entry['path']);
            $bytes = filesize($path);
            $hash = hash_file('sha256', $path);
            if ($bytes !== $entry['bytes'] || $hash === false || ! hash_equals($entry['sha256'], $hash)) {
                throw new RuntimeException("Packaged item {$itemId} does not match its manifest digest.");
            }

            $artifact = $repository->item($itemId);
            if ($artifact === null
                || ($artifact['item_id'] ?? null) !== $itemId
                || ($artifact['complete'] ?? null) !== true
                || ($artifact['gaps'] ?? null) !== []) {
                throw new RuntimeException("Packaged item {$itemId} failed semantic validation.");
            }
            $formatVersion = $artifact['format_version'];
            $artifactVersions[$formatVersion] = true;
            if ($formatVersion === ItemHistoryArtifact::REVERSIBLE_DELTA_FORMAT_VERSION) {
                $parserVersion = $artifact['parser_format_version'] ?? null;
                if ($parserVersion !== ItemHistoryArtifact::REVERSIBLE_DELTA_PARSER_FORMAT_VERSION) {
                    throw new RuntimeException("Packaged item {$itemId} has an incompatible parser format.");
                }
                $parserVersions[$parserVersion] = true;
            } elseif (array_key_exists('parser_format_version', $artifact)) {
                $parserVersion = $artifact['parser_format_version'];
                if ($parserVersion !== ItemHistoryArtifact::DIRECT_DETAIL_PARSER_FORMAT_VERSION) {
                    throw new RuntimeException("Packaged item {$itemId} has an incompatible parser format.");
                }
                $parserVersions[$parserVersion] = true;
            }

            $revisionCount += $artifact['revision_count'];
            $artifactBytes += $bytes;
            if ($artifact['first_observed_at'] !== null
                && ($firstObserved === null || $artifact['first_observed_at'] < $firstObserved)) {
                $firstObserved = $artifact['first_observed_at'];
            }
            if ($artifact['last_observed_at'] !== null
                && ($lastObserved === null || $artifact['last_observed_at'] > $lastObserved)) {
                $lastObserved = $artifact['last_observed_at'];
            }
            $generatedInstant = $this->generatedInstant($artifact['generated_at']);
            if ($generatedInstant === null) {
                throw new RuntimeException("Packaged item {$itemId} has an invalid generation timestamp.");
            }
            if ($latestGeneratedInstant === null || $generatedInstant > $latestGeneratedInstant) {
                $latestGeneratedInstant = $generatedInstant;
                $latestGenerated = $artifact['generated_at'];
            }

            clearstatcache(true, $path);
            if (filesize($path) !== $entry['bytes']
                || hash_file('sha256', $path) !== $entry['sha256']) {
                throw new RuntimeException("Packaged item {$itemId} changed during validation.");
            }

            if ($progress !== null
                && (($index + 1) === count($manifestFiles) || ($index + 1) % 5_000 === 0)) {
                $progress('validate', ['current' => $index + 1, 'total' => count($manifestFiles)]);
            }
        }

        $observedArtifactVersions = array_map('intval', array_keys($artifactVersions));
        sort($observedArtifactVersions, SORT_NUMERIC);
        $observedParserVersions = array_map('intval', array_keys($parserVersions));
        sort($observedParserVersions, SORT_NUMERIC);
        if ($observedArtifactVersions !== $package['artifact_format_versions']
            || $observedParserVersions !== $package['parser_format_versions']
            || $firstObserved !== $package['first_observed_at']
            || $lastObserved !== $package['last_observed_at']
            || $latestGenerated !== $package['latest_generated_at']) {
            throw new RuntimeException('The packaged item metadata does not match the descriptor summary.');
        }

        $completionBytes = filesize($completionPath);
        if ($completionBytes === false
            || count($manifestFiles) !== $package['stats']['item_count']
            || $revisionCount !== $package['stats']['revision_count']
            || $artifactBytes !== $package['stats']['artifact_bytes']
            || count($manifestFiles) + 2 !== $package['stats']['file_count']
            || $artifactBytes + $manifestBytes + $completionBytes !== $package['stats']['unpacked_bytes']) {
            throw new RuntimeException('The packaged dataset totals do not match its descriptor.');
        }

        return [
            'stats' => [
                'item_count' => count($manifestFiles),
                'revision_count' => $revisionCount,
                'artifact_bytes' => $artifactBytes,
                'file_count' => count($manifestFiles) + 2,
                'unpacked_bytes' => $artifactBytes + $manifestBytes + $completionBytes,
            ],
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function validateManifestHeader(array $manifest, string $dataset): void
    {
        $this->assertExactKeys($manifest, [
            'schema',
            'artifact_type',
            'format_version',
            'dataset',
            'artifact_schema',
            'artifact_format_versions',
            'parser_format_versions',
            'first_observed_at',
            'last_observed_at',
            'latest_generated_at',
            'stats',
            'files',
        ], 'item history manifest');

        if ($manifest['schema'] !== ItemHistoryDataset::DATASET_SCHEMA
            || $manifest['artifact_type'] !== 'manifest'
            || $manifest['format_version'] !== ItemHistoryDataset::DATASET_FORMAT_VERSION
            || $manifest['dataset'] !== $dataset
            || $manifest['artifact_schema'] !== ItemHistoryArtifact::SCHEMA
            || ! $this->validArtifactVersions($manifest['artifact_format_versions'])
            || ! $this->validParserVersions($manifest['parser_format_versions'])
            || ! is_string($manifest['first_observed_at'])
            || ! $this->validObservedTimestamp($manifest['first_observed_at'])
            || ! is_string($manifest['last_observed_at'])
            || ! $this->validObservedTimestamp($manifest['last_observed_at'])
            || $manifest['last_observed_at'] < $manifest['first_observed_at']
            || ! is_string($manifest['latest_generated_at'])
            || ! $this->validGeneratedTimestamp($manifest['latest_generated_at'])
            || ! is_array($manifest['stats']) || array_is_list($manifest['stats'])
            || ! is_array($manifest['files']) || ! array_is_list($manifest['files'])) {
            throw new RuntimeException('The staged item history manifest is incompatible or incomplete.');
        }

        $this->assertExactKeys(
            $manifest['stats'],
            ['item_count', 'revision_count', 'artifact_bytes', 'file_count'],
            'item history manifest statistics',
        );
        foreach (['item_count', 'revision_count', 'artifact_bytes', 'file_count'] as $field) {
            if (! is_int($manifest['stats'][$field]) || $manifest['stats'][$field] < 1) {
                throw new RuntimeException("The item history manifest statistic {$field} is invalid.");
            }
        }
        if ($manifest['stats']['revision_count'] < $manifest['stats']['item_count']
            || $manifest['stats']['file_count'] !== $manifest['stats']['item_count'] + 2) {
            throw new RuntimeException('The item history manifest counts are inconsistent.');
        }
    }

    /** @param list<mixed> $files */
    private function validateManifestFiles(array &$files, int $expectedCount): void
    {
        if (count($files) !== $expectedCount) {
            throw new RuntimeException('The item history manifest file inventory count is inconsistent.');
        }

        $previousPath = null;
        foreach ($files as $entry) {
            if (! is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException('The item history manifest contains an invalid file entry.');
            }
            $this->assertExactKeys($entry, ['path', 'bytes', 'sha256'], 'item history manifest file');
            $path = $entry['path'];
            $bytes = $entry['bytes'];
            $sha256 = $entry['sha256'];
            if (! is_string($path)
                || preg_match('#^items/([a-f0-9]{2})/([1-9][0-9]*)\.json$#D', $path, $match) !== 1
                || ! is_int($bytes) || $bytes < 2 || $bytes > ItemHistoryArtifact::MAX_ITEM_BYTES
                || ! is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
                || ($previousPath !== null && strcmp($previousPath, $path) >= 0)) {
                throw new RuntimeException('The item history manifest file inventory is invalid or unsorted.');
            }
            $itemId = filter_var($match[2], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
            ]);
            if ($itemId === false || $match[1] !== substr(hash('sha256', (string) $itemId), 0, 2)) {
                throw new RuntimeException("Manifest item {$path} is in the wrong shard.");
            }
            $previousPath = $path;
        }
    }

    /** @param array<string, mixed> $completion */
    private function validateCompletion(array $completion, string $dataset): void
    {
        $this->assertExactKeys($completion, [
            'schema',
            'artifact_type',
            'format_version',
            'dataset',
            'manifest_sha256',
            'manifest_bytes',
            'item_count',
            'revision_count',
            'artifact_bytes',
            'file_count',
        ], 'item history completion marker');

        if ($completion['schema'] !== ItemHistoryDataset::DATASET_SCHEMA
            || $completion['artifact_type'] !== 'completion'
            || $completion['format_version'] !== ItemHistoryDataset::DATASET_FORMAT_VERSION
            || $completion['dataset'] !== $dataset
            || ! is_string($completion['manifest_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $completion['manifest_sha256']) !== 1
            || ! is_int($completion['manifest_bytes']) || $completion['manifest_bytes'] < 2) {
            throw new RuntimeException('The staged item history completion marker is incompatible or incomplete.');
        }
        foreach (['item_count', 'revision_count', 'artifact_bytes', 'file_count'] as $field) {
            if (! is_int($completion[$field]) || $completion[$field] < 1) {
                throw new RuntimeException("The item history completion statistic {$field} is invalid.");
            }
        }
    }

    /**
     * @param  list<int>  $artifactVersions
     * @param  list<int>  $parserVersions
     * @param  list<array{path: string, bytes: int, sha256: string}>  $files
     */
    private function datasetKey(array $artifactVersions, array $parserVersions, array $files): string
    {
        $context = hash_init('sha256');
        hash_update($context, self::DATASET_HASH_DOMAIN);
        hash_update($context, ItemHistoryArtifact::SCHEMA."\0");
        hash_update($context, implode(',', $artifactVersions)."\0");
        hash_update($context, implode(',', $parserVersions)."\0");
        foreach ($files as $file) {
            hash_update(
                $context,
                $file['path']."\0".(string) $file['bytes']."\0".$file['sha256']."\n",
            );
        }

        return hash_final($context);
    }

    private function itemIdFromManifestPath(string $relative): int
    {
        if (preg_match('#^items/[a-f0-9]{2}/([1-9][0-9]*)\.json$#D', $relative, $match) !== 1) {
            throw new RuntimeException("Manifest item path {$relative} is invalid.");
        }
        $itemId = filter_var($match[1], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
        ]);
        if ($itemId === false) {
            throw new RuntimeException("Manifest item path {$relative} has an invalid item ID.");
        }

        return $itemId;
    }

    /**
     * @param  list<array{path: string, bytes: int, sha256: string}>  $expected
     */
    private function assertItemInventory(string $datasetRoot, array $expected, bool $verifyHashes): void
    {
        $this->assertDatasetTopLevel($datasetRoot);
        $itemsRoot = SafePath::assertContained(
            $datasetRoot.'/items',
            $datasetRoot,
            'Packaged item history items directory',
        );
        $position = 0;
        $shards = scandir($itemsRoot);
        if ($shards === false) {
            throw new RuntimeException('Unable to enumerate packaged item shards.');
        }
        foreach ($shards as $shard) {
            if ($shard === '.' || $shard === '..') {
                continue;
            }
            $shardPath = $itemsRoot.'/'.$shard;
            if (preg_match('/^[a-f0-9]{2}$/D', $shard) !== 1 || is_link($shardPath) || ! is_dir($shardPath)) {
                throw new RuntimeException('The packaged dataset contains an unsafe item shard.');
            }
            $shardPath = SafePath::assertContained($shardPath, $itemsRoot, 'Packaged item history shard');
            $entries = scandir($shardPath);
            if ($entries === false) {
                throw new RuntimeException("Unable to enumerate packaged item shard {$shard}.");
            }
            $shardFiles = 0;
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $shardPath.'/'.$entry;
                if (preg_match('/^([1-9][0-9]*)\.json$/D', $entry, $match) !== 1
                    || is_link($path) || ! is_file($path)) {
                    throw new RuntimeException("The packaged item shard {$shard} contains an unexpected entry.");
                }
                SafePath::assertContained($path, $shardPath, 'Packaged item history artifact');
                $itemId = filter_var($match[1], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
                ]);
                if ($itemId === false || $shard !== substr(hash('sha256', (string) $itemId), 0, 2)) {
                    throw new RuntimeException("Packaged item {$itemId} is in the wrong shard.");
                }
                $relative = "items/{$shard}/{$entry}";
                if (! isset($expected[$position]) || $expected[$position]['path'] !== $relative) {
                    throw new RuntimeException('The packaged item files do not exactly match the manifest inventory.');
                }
                if ($verifyHashes) {
                    $bytes = filesize($path);
                    $hash = hash_file('sha256', $path);
                    if ($bytes !== $expected[$position]['bytes'] || $hash === false
                        || ! hash_equals($expected[$position]['sha256'], $hash)) {
                        throw new RuntimeException("Packaged item {$itemId} changed after validation.");
                    }
                }
                $position++;
                $shardFiles++;
            }
            if ($shardFiles === 0) {
                throw new RuntimeException("The packaged item shard {$shard} is empty.");
            }
        }
        if ($position !== count($expected)) {
            throw new RuntimeException('The packaged item files do not exactly match the manifest inventory.');
        }
    }

    private function assertDatasetTopLevel(string $datasetRoot): void
    {
        $entries = scandir($datasetRoot);
        if ($entries === false) {
            throw new RuntimeException('Unable to enumerate the item history dataset root.');
        }
        $entries = array_values(array_filter(
            $entries,
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
        sort($entries, SORT_STRING);
        if ($entries !== ['COMPLETE.json', 'items', 'manifest.json']) {
            throw new RuntimeException('The item history dataset contains unexpected top-level entries.');
        }
        foreach (['manifest.json', 'COMPLETE.json'] as $file) {
            $path = $datasetRoot.'/'.$file;
            if (is_link($path) || ! is_file($path)) {
                throw new RuntimeException("The item history dataset has an unsafe {$file}.");
            }
            SafePath::assertContained($path, $datasetRoot, "Item history {$file}");
        }
        $items = $datasetRoot.'/items';
        if (is_link($items) || ! is_dir($items)) {
            throw new RuntimeException('The item history dataset has an unsafe items directory.');
        }
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<array{path: string, bytes: int, sha256: string}>
     */
    private function revalidateDatasetInventory(
        string $datasetRoot,
        string $dataset,
        int $maximumFiles,
        array $package,
    ): array {
        $datasetRoot = SafePath::existingDirectory($datasetRoot, 'Staged item history dataset');
        $this->assertDatasetTopLevel($datasetRoot);

        $manifestPath = $datasetRoot.'/manifest.json';
        $manifest = $this->decodeJsonFile(
            $manifestPath,
            ItemHistoryDataset::MAX_MANIFEST_BYTES,
            'staged item history manifest',
        );
        $this->validateManifestHeader($manifest, $dataset);
        if ($manifest['stats']['file_count'] > $maximumFiles) {
            throw new RuntimeException('The item history dataset exceeds the configured file-count limit.');
        }
        foreach (['item_count', 'revision_count', 'artifact_bytes', 'file_count'] as $field) {
            if ($manifest['stats'][$field] !== $package['stats'][$field]) {
                throw new RuntimeException("The staged manifest {$field} changed after validation.");
            }
        }
        foreach ([
            'artifact_format_versions',
            'parser_format_versions',
            'first_observed_at',
            'last_observed_at',
            'latest_generated_at',
        ] as $field) {
            if ($manifest[$field] !== $package[$field]) {
                throw new RuntimeException("The staged manifest {$field} changed after validation.");
            }
        }

        $inventory = &$manifest['files'];
        $this->validateManifestFiles($inventory, $manifest['stats']['item_count']);
        if (! hash_equals(
            $dataset,
            $this->datasetKey(
                $manifest['artifact_format_versions'],
                $manifest['parser_format_versions'],
                $inventory,
            ),
        )) {
            throw new RuntimeException('The staged dataset key changed after validation.');
        }

        $completionPath = $datasetRoot.'/COMPLETE.json';
        $completion = $this->decodeJsonFile(
            $completionPath,
            ItemHistoryDataset::MAX_COMPLETION_BYTES,
            'staged item history completion marker',
        );
        $this->validateCompletion($completion, $dataset);
        $manifestBytes = filesize($manifestPath);
        $manifestHash = hash_file('sha256', $manifestPath);
        $completionBytes = filesize($completionPath);
        if ($manifestBytes === false || $manifestHash === false || $completionBytes === false
            || $completion['manifest_bytes'] !== $manifestBytes
            || ! hash_equals($completion['manifest_sha256'], $manifestHash)
            || $package['stats']['unpacked_bytes'] !== $package['stats']['artifact_bytes']
                + $manifestBytes + $completionBytes) {
            throw new RuntimeException('The staged completion marker changed after validation.');
        }
        foreach (['item_count', 'revision_count', 'artifact_bytes', 'file_count'] as $field) {
            if ($completion[$field] !== $package['stats'][$field]) {
                throw new RuntimeException("The staged completion marker {$field} changed after validation.");
            }
        }
        $this->assertItemInventory($datasetRoot, $inventory, true);

        return $inventory;
    }

    /** @param list<array{path: string, bytes: int, sha256: string}> $expected */
    private function datasetsMatch(string $staged, string $installed, array $expected, int $maximumFiles): bool
    {
        try {
            if (count($expected) + 2 > $maximumFiles || is_link($installed) || ! is_dir($installed)) {
                return false;
            }
            $installedRoot = SafePath::assertContained(
                $installed,
                dirname($installed),
                'Installed item history dataset',
            );
            foreach (['manifest.json', 'COMPLETE.json'] as $relative) {
                $left = $staged.'/'.$relative;
                $right = $installedRoot.'/'.$relative;
                if (is_link($right) || ! is_file($right) || filesize($left) !== filesize($right)) {
                    return false;
                }
                $right = SafePath::assertContained($right, $installedRoot, 'Installed item history metadata');
                $leftHash = hash_file('sha256', $left);
                $rightHash = hash_file('sha256', $right);
                if ($leftHash === false || $rightHash === false || ! hash_equals($leftHash, $rightHash)) {
                    return false;
                }
            }
            $this->assertItemInventory($installedRoot, $expected, true);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<array{path: string, bytes: int, sha256: string}> $inventory */
    private function normalizeInstalledDatasetPermissions(string $datasetDirectory, array $inventory): void
    {
        if (is_link($datasetDirectory) || ! is_dir($datasetDirectory)) {
            throw new RuntimeException('The verified item history dataset directory became unsafe before activation.');
        }
        $datasetRoot = SafePath::existingDirectory($datasetDirectory, 'Verified item history dataset');
        $itemsPath = $datasetRoot.'/items';
        if (is_link($itemsPath) || ! is_dir($itemsPath)) {
            throw new RuntimeException('The verified item history items directory became unsafe before activation.');
        }
        $itemsRoot = SafePath::assertContained(
            $itemsPath,
            $datasetRoot,
            'Verified item history items directory',
        );

        $directories = [$datasetRoot, $itemsRoot];
        $files = [$datasetRoot.'/manifest.json', $datasetRoot.'/COMPLETE.json'];
        foreach ($inventory as $entry) {
            $path = $datasetRoot.'/'.$entry['path'];
            $shardPath = dirname($path);
            if (is_link($shardPath) || ! is_dir($shardPath)) {
                throw new RuntimeException('A verified item history shard became unsafe before activation.');
            }
            $directories[] = SafePath::assertContained(
                $shardPath,
                $itemsRoot,
                'Verified item history shard',
            );
            $files[] = $path;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        foreach ($files as $path) {
            $this->permissions->normalizeFile(
                $path,
                $datasetRoot,
                'item history JSON file',
            );
        }
        foreach (array_values(array_unique($directories)) as $path) {
            $this->permissions->normalizeDirectory(
                $path,
                $datasetRoot,
                'item history dataset directory',
            );
        }
    }

    /** @return array<string, mixed> */
    private function githubRelease(string $repository, string $tag, array $limits): array
    {
        $url = 'https://api.github.com/repos/'.$repository.'/releases/tags/'.rawurlencode($tag);
        $response = Http::acceptJson()
            ->withHeaders(['User-Agent' => 'modern-allaclone-item-history-installer'])
            ->connectTimeout($limits['connect_timeout'])
            ->timeout(min($limits['download_timeout'], 120))
            ->withOptions([
                'on_headers' => function (ResponseInterface $response): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && ctype_digit($length)
                        && (int) $length > self::MAX_RELEASE_METADATA_BYTES) {
                        throw new RuntimeException('GitHub release metadata exceeds the safe size limit.');
                    }
                },
            ])
            ->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("GitHub release lookup failed with HTTP {$response->status()}.");
        }
        if (strlen($response->body()) > self::MAX_RELEASE_METADATA_BYTES) {
            throw new RuntimeException('GitHub release metadata exceeds the safe size limit.');
        }

        try {
            $release = $response->json();
        } catch (Throwable $exception) {
            throw new RuntimeException('GitHub returned invalid release metadata.', 0, $exception);
        }
        if (! is_array($release) || ! is_array($release['assets'] ?? null)) {
            throw new RuntimeException('GitHub release metadata has no asset list.');
        }

        return $release;
    }

    /** @return array<string, mixed> */
    private function githubAsset(array $release, string $name): array
    {
        $matches = array_values(array_filter(
            $release['assets'],
            static fn (mixed $asset): bool => is_array($asset) && ($asset['name'] ?? null) === $name,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException("GitHub release must contain exactly one {$name} asset.");
        }

        return $matches[0];
    }

    private function githubDigest(array $asset, string $label): string
    {
        $digest = $asset['digest'] ?? null;
        if (! is_string($digest) || preg_match('/^sha256:([a-f0-9]{64})$/D', $digest, $match) !== 1) {
            throw new RuntimeException("The GitHub {$label} asset has no usable SHA-256 digest.");
        }

        return $match[1];
    }

    private function downloadGithubAsset(
        array $asset,
        string $repository,
        string $destination,
        int $maximumBytes,
        array $limits,
    ): void {
        $url = $asset['browser_download_url'] ?? null;
        $declaredBytes = $asset['size'] ?? null;
        if (! is_string($url) || ! is_int($declaredBytes)
            || $declaredBytes < 1 || $declaredBytes > $maximumBytes) {
            throw new RuntimeException('GitHub release asset metadata is invalid or exceeds the configured limit.');
        }
        $this->assertGithubDownloadUrl($url, $repository);
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('Refusing to overwrite an existing download file.');
        }

        try {
            $response = Http::accept('*/*')
                ->withHeaders(['User-Agent' => 'modern-allaclone-item-history-installer'])
                ->connectTimeout($limits['connect_timeout'])
                ->timeout($limits['download_timeout'])
                ->withOptions([
                    'sink' => $destination,
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['https'],
                    ],
                    'on_headers' => static function (ResponseInterface $response) use ($maximumBytes): void {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && ctype_digit($length) && (int) $length > $maximumBytes) {
                            throw new RuntimeException('GitHub release asset exceeds the configured download limit.');
                        }
                    },
                    'progress' => static function (
                        int $downloadTotal,
                        int $downloadedBytes,
                        int $uploadTotal,
                        int $uploadedBytes,
                    ) use ($maximumBytes): void {
                        if ($downloadTotal > $maximumBytes || $downloadedBytes > $maximumBytes) {
                            throw new RuntimeException('GitHub release asset exceeded the configured download limit.');
                        }
                    },
                ])
                ->get($url);
        } catch (Throwable $exception) {
            @unlink($destination);
            throw new RuntimeException('Unable to download the GitHub release asset.', 0, $exception);
        }

        if (! $response->successful()) {
            @unlink($destination);
            throw new RuntimeException("GitHub asset download failed with HTTP {$response->status()}.");
        }
        clearstatcache(true, $destination);
        $actualBytes = filesize($destination);
        if ($actualBytes === false || $actualBytes !== $declaredBytes || $actualBytes > $maximumBytes) {
            @unlink($destination);
            throw new RuntimeException('Downloaded GitHub asset size does not match its metadata.');
        }
        $this->assertFileHash($destination, $this->githubDigest($asset, 'release'), 'Downloaded GitHub asset');
    }

    private function assertGithubDownloadUrl(string $url, string $repository): void
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'github.com'
            || ! is_string($path)
            || ! str_starts_with(strtolower(rawurldecode($path)), '/'.strtolower($repository).'/releases/download/')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || isset($parts['query'])) {
            throw new RuntimeException('GitHub release asset URL is outside the configured repository.');
        }
    }

    private function validateRepository(string $repository): string
    {
        $repository = trim($repository);
        if (preg_match('/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/D', $repository) !== 1) {
            throw new RuntimeException('Configured GitHub release repository must be in owner/repository form.');
        }

        return $repository;
    }

    /**
     * @param  array<string, int>  $limits
     * @return array{max_download_bytes: int, max_unpacked_bytes: int, max_files: int, connect_timeout: int, download_timeout: int}
     */
    private function resolveLimits(array $limits): array
    {
        $resolved = [
            'max_download_bytes' => $limits['max_download_bytes'] ?? self::DEFAULT_MAX_DOWNLOAD_BYTES,
            'max_unpacked_bytes' => $limits['max_unpacked_bytes'] ?? self::DEFAULT_MAX_UNPACKED_BYTES,
            'max_files' => $limits['max_files'] ?? self::DEFAULT_MAX_FILES,
            'connect_timeout' => $limits['connect_timeout'] ?? 15,
            'download_timeout' => $limits['download_timeout'] ?? 1_800,
        ];
        foreach ($resolved as $name => $value) {
            if (! is_int($value) || $value < 1) {
                throw new RuntimeException("Item history installer limit {$name} must be a positive integer.");
            }
        }
        if ($resolved['max_download_bytes'] > self::MAX_DOWNLOAD_CEILING
            || $resolved['max_unpacked_bytes'] > self::MAX_UNPACKED_CEILING
            || $resolved['max_files'] > self::MAX_FILES_CEILING
            || $resolved['connect_timeout'] > 300
            || $resolved['download_timeout'] > 86_400) {
            throw new RuntimeException('Item history installer limits exceed their safety ceilings.');
        }

        return $resolved;
    }

    private function snapshotLocalArchive(
        string $sourcePath,
        string $destination,
        string $expectedSha256,
        int $maximumBytes,
    ): void {
        $sourcePath = $this->safeLocalArchive($sourcePath);
        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new RuntimeException('Unable to open the local item history ZIP.');
        }

        $target = null;
        try {
            $before = fstat($source);
            $sourceBytes = is_array($before) ? ($before['size'] ?? null) : null;
            $mode = is_array($before) ? ($before['mode'] ?? null) : null;
            if (! is_int($sourceBytes) || $sourceBytes < 1 || $sourceBytes > $maximumBytes
                || (is_int($mode) && (($mode & 0170000) !== 0100000))) {
                throw new RuntimeException('The local item history ZIP is not a safe regular file or exceeds the download limit.');
            }

            $target = fopen($destination, 'x+b');
            if ($target === false) {
                throw new RuntimeException('Unable to create a private snapshot of the local item history ZIP.');
            }
            $hash = hash_init('sha256');
            $copied = 0;
            while (! feof($source)) {
                $chunk = fread($source, self::COPY_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read the local item history ZIP.');
                }
                if ($chunk === '') {
                    continue;
                }
                $bytes = strlen($chunk);
                if ($bytes > $maximumBytes - $copied) {
                    throw new RuntimeException('The local item history ZIP exceeds the configured download limit.');
                }
                hash_update($hash, $chunk);
                $this->writeAll($target, $chunk, 'local item history ZIP snapshot');
                $copied += $bytes;
            }
            $after = fstat($source);
            if (! is_array($after) || ($after['size'] ?? null) !== $sourceBytes
                || $copied !== $sourceBytes || ! fflush($target)
                || (function_exists('fsync') && ! fsync($target))) {
                throw new RuntimeException('The local item history ZIP changed while it was being snapshotted.');
            }
            $actualHash = hash_final($hash);
            if (! hash_equals($expectedSha256, $actualHash)) {
                throw new RuntimeException('Item history ZIP failed SHA-256 verification.');
            }
        } finally {
            fclose($source);
            if (is_resource($target)) {
                fclose($target);
            }
            if (file_exists($destination)) {
                $actualHash = hash_file('sha256', $destination);
                if ($actualHash === false || ! hash_equals($expectedSha256, $actualHash)) {
                    @unlink($destination);
                }
            }
        }
    }

    private function safeLocalArchive(string $path): string
    {
        if (! str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\/]/', $path) !== 1
            && ! str_starts_with($path, '\\\\')) {
            throw new RuntimeException('Item history ZIP path must be absolute.');
        }
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('Item history ZIP is missing or is not a regular file.');
        }
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException('Unable to resolve the item history ZIP path.');
        }

        return SafePath::normalize($resolved);
    }

    private function prepareArtifactRoot(string $artifactDirectory): string
    {
        $root = SafePath::createDirectory($artifactDirectory, 'Item history artifact root');
        $this->permissions->normalizeDirectory($root, $root, 'item history artifact root');
        $datasetsPath = $root.'/datasets';
        if (is_link($datasetsPath)) {
            throw new RuntimeException('Item history datasets directory cannot be a symbolic link.');
        }
        $datasets = SafePath::createDirectory($datasetsPath, 'Item history datasets directory');
        SafePath::assertContained($datasets, $root, 'Item history datasets directory');
        $this->permissions->normalizeDirectory($datasets, $root, 'item history datasets directory');

        return $root;
    }

    private function assertFileHash(string $path, string $expected, string $label): void
    {
        $expected = $this->normalizeSha256($expected, "{$label} expected SHA-256");
        $actual = hash_file('sha256', $path);
        if ($actual === false || ! hash_equals($expected, $actual)) {
            throw new RuntimeException("{$label} failed SHA-256 verification.");
        }
    }

    private function normalizeSha256(mixed $hash, string $label): string
    {
        if (! is_string($hash)) {
            throw new RuntimeException("{$label} is invalid.");
        }
        $hash = strtolower(trim($hash));
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuntimeException("{$label} is invalid.");
        }

        return $hash;
    }

    /** @return array<string, mixed> */
    private function decodeJsonFile(string $path, int $maximumBytes, string $label): array
    {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException("The {$label} is missing or unsafe.");
        }
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 2 || $bytes > $maximumBytes) {
            throw new RuntimeException("The {$label} has an invalid size.");
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Unable to read the {$label}.");
        }
        try {
            $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The {$label} is not valid JSON.", 0, $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("The {$label} must contain a JSON object.");
        }

        return $decoded;
    }

    private function validArtifactVersions(mixed $versions): bool
    {
        return $this->validVersionList($versions, [
            ItemHistoryArtifact::FORMAT_VERSION,
            ItemHistoryArtifact::REVERSIBLE_DELTA_FORMAT_VERSION,
        ], false);
    }

    private function validParserVersions(mixed $versions): bool
    {
        return $this->validVersionList($versions, [
            ItemHistoryArtifact::DIRECT_DETAIL_PARSER_FORMAT_VERSION,
            ItemHistoryArtifact::REVERSIBLE_DELTA_PARSER_FORMAT_VERSION,
        ], true);
    }

    /** @param mixed $versions @param list<int> $allowed */
    private function validVersionList(mixed $versions, array $allowed, bool $mayBeEmpty): bool
    {
        if (! is_array($versions) || ! array_is_list($versions) || (! $mayBeEmpty && $versions === [])) {
            return false;
        }
        $previous = null;
        foreach ($versions as $version) {
            if (! is_int($version) || ! in_array($version, $allowed, true)
                || ($previous !== null && $version <= $previous)) {
                return false;
            }
            $previous = $version;
        }

        return true;
    }

    private function validObservedTimestamp(string $timestamp): bool
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s',
            $timestamp,
            new DateTimeZone('UTC'),
        );

        return $date !== false && $date->format('Y-m-d\TH:i:s') === $timestamp;
    }

    private function validGeneratedTimestamp(string $timestamp): bool
    {
        return $this->generatedInstant($timestamp) !== null;
    }

    private function generatedInstant(string $timestamp): ?string
    {
        foreach ([
            ['parse' => '!Y-m-d\TH:i:s\Z', 'round_trip' => 'Y-m-d\TH:i:s\Z'],
            ['parse' => '!Y-m-d\TH:i:s.v\Z', 'round_trip' => 'Y-m-d\TH:i:s.v\Z'],
        ] as $format) {
            $date = DateTimeImmutable::createFromFormat(
                $format['parse'],
                $timestamp,
                new DateTimeZone('UTC'),
            );
            if ($date !== false && $date->format($format['round_trip']) === $timestamp) {
                return $date->format('U.u');
            }
        }

        return null;
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function assertExactKeys(array $value, array $expected, string $label): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException("The {$label} has unexpected or missing fields.");
        }
    }

    private function removePrivateDirectory(string $directory, string $artifactRoot, string $requiredPrefix): void
    {
        if (! file_exists($directory) && ! is_link($directory)) {
            return;
        }
        if (is_link($directory)) {
            throw new RuntimeException('Refusing to recurse into a symbolic-link item history work directory.');
        }
        $resolved = SafePath::assertContained($directory, $artifactRoot, 'Item history work directory');
        if (dirname($resolved) !== SafePath::normalize($artifactRoot)
            || ! str_starts_with(basename($resolved), $requiredPrefix)) {
            throw new RuntimeException('Refusing to remove an unexpected item history directory.');
        }
        $this->removeDirectoryTree($resolved, $resolved);
    }

    private function cleanupWorkDirectory(
        string $directory,
        string $artifactRoot,
        string $requiredPrefix,
        bool $bestEffort,
    ): void {
        try {
            $this->removePrivateDirectory($directory, $artifactRoot, $requiredPrefix);
        } catch (Throwable $exception) {
            if (! $bestEffort) {
                throw $exception;
            }

            // The verified dataset and optional CURRENT switch are already durable.
            // A transient cleanup failure must not misreport that commit as failed.
        }
    }

    private function removeDirectoryTree(string $directory, string $root): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Unable to enumerate an item history work directory.');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_link($path) || is_file($path)) {
                if (! unlink($path)) {
                    throw new RuntimeException('Unable to remove a staged item history file.');
                }
            } elseif (is_dir($path)) {
                $child = SafePath::assertContained($path, $root, 'Item history work directory child');
                $this->removeDirectoryTree($child, $root);
            } else {
                throw new RuntimeException('An item history work directory contains an unsafe entry.');
            }
        }
        if (! rmdir($directory)) {
            throw new RuntimeException('Unable to remove an item history work directory.');
        }
    }
}

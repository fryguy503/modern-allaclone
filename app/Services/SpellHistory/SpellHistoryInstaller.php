<?php

namespace App\Services\SpellHistory;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

final class SpellHistoryInstaller
{
    public const PACKAGE_SCHEMA = 'modern-allaclone.spell-history-package';

    public const PACKAGE_VERSION = 1;

    public const DESCRIPTOR_FILENAME = 'spell-history-package.json';

    private const MAX_DESCRIPTOR_BYTES = 262_144;

    private const MAX_RELEASE_METADATA_BYTES = 4_194_304;

    private const COPY_CHUNK_BYTES = 1_048_576;

    private const DISK_HEADROOM_BYTES = 16_777_216;

    public function __construct(
        private readonly SpellHistoryActivator $activator,
        private readonly SpellHistoryMutationLock $mutationLock,
    ) {}

    /**
     * @param  array{max_download_bytes?: int, max_unpacked_bytes?: int, max_files?: int, connect_timeout?: int, download_timeout?: int}  $limits
     * @param  callable(string, array<string, mixed>): void|null  $progress
     * @return array{dataset: string, reused: bool, activated: bool, previous_dataset: ?string, path: string, stats: array<string, int>}
     */
    public function installFromFile(
        string $archivePath,
        string $expectedSha256,
        string $artifactDirectory,
        bool $activate = true,
        array $limits = [],
        ?callable $progress = null,
    ): array {
        $archive = $this->safeLocalArchive($archivePath);
        $expectedHash = $this->normalizeSha256($expectedSha256, 'Expected archive SHA-256');
        $resolvedLimits = $this->resolveLimits($limits);

        return $this->installArchive(
            $archive,
            $expectedHash,
            $artifactDirectory,
            $activate,
            $resolvedLimits,
            null,
            $progress,
        );
    }

    /**
     * @param  array{max_download_bytes?: int, max_unpacked_bytes?: int, max_files?: int, connect_timeout?: int, download_timeout?: int}  $limits
     * @param  callable(string, array<string, mixed>): void|null  $progress
     * @return array{dataset: string, reused: bool, activated: bool, previous_dataset: ?string, path: string, stats: array<string, int>}
     */
    public function installFromRelease(
        string $tag,
        string $repository,
        string $expectedSha256,
        string $artifactDirectory,
        bool $activate = true,
        array $limits = [],
        ?callable $progress = null,
    ): array {
        $repository = $this->validateRepository($repository);
        $expectedHash = $this->normalizeSha256($expectedSha256, 'Pinned release archive SHA-256');
        $tag = trim($tag);
        if ($tag === '' || strlen($tag) > 200 || preg_match('/[\x00-\x1f\x7f]/', $tag) === 1) {
            throw new RuntimeException('GitHub release tag is invalid.');
        }

        $resolvedLimits = $this->resolveLimits($limits);
        $artifactRoot = $this->prepareArtifactRoot($artifactDirectory);
        $downloadDirectory = $artifactRoot.'/.download-'.bin2hex(random_bytes(16));
        if (! mkdir($downloadDirectory, 0700)) {
            throw new RuntimeException('Unable to create the spell history download directory.');
        }

        try {
            $release = $this->githubRelease($repository, $tag, $resolvedLimits);
            $descriptorAsset = $this->githubAsset($release, self::DESCRIPTOR_FILENAME);
            $descriptorDigest = $this->githubDigest($descriptorAsset, 'release descriptor');
            $descriptorPath = $downloadDirectory.'/'.self::DESCRIPTOR_FILENAME;
            $this->downloadGithubAsset(
                $descriptorAsset,
                $repository,
                $descriptorPath,
                min(self::MAX_DESCRIPTOR_BYTES, $resolvedLimits['max_download_bytes']),
                $resolvedLimits,
            );
            $this->assertFileHash($descriptorPath, $descriptorDigest, 'Downloaded release descriptor');
            $descriptor = $this->decodeJsonFile(
                $descriptorPath,
                self::MAX_DESCRIPTOR_BYTES,
                'spell history release descriptor',
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
                $progress('download', ['asset' => $package['archive']['name'], 'bytes' => $package['archive']['bytes']]);
            }
            $this->downloadGithubAsset(
                $archiveAsset,
                $repository,
                $archivePath,
                $resolvedLimits['max_download_bytes'],
                $resolvedLimits,
            );

            return $this->installArchive(
                $archivePath,
                $archiveDigest,
                $artifactRoot,
                $activate,
                $resolvedLimits,
                $descriptor,
                $progress,
            );
        } finally {
            $this->removePrivateDirectory($downloadDirectory, $artifactRoot, '.download-');
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
            throw new RuntimeException('The PHP zip extension is required to install spell history packages.');
        }

        $archive = $this->safeLocalArchive($archivePath);
        $archiveBytes = filesize($archive);
        if ($archiveBytes === false || $archiveBytes < 1 || $archiveBytes > $limits['max_download_bytes']) {
            throw new RuntimeException('The spell history ZIP exceeds the configured download limit.');
        }
        $this->assertFileHash($archive, $expectedSha256, 'Spell history ZIP');

        $artifactRoot = $this->prepareArtifactRoot($artifactDirectory);
        $stageDirectory = $artifactRoot.'/.installing-'.bin2hex(random_bytes(16));

        try {
            $zip = new ZipArchive;
            $opened = $zip->open($archive, ZipArchive::RDONLY);
            if ($opened !== true) {
                throw new RuntimeException("Unable to open the spell history ZIP (code {$opened}).");
            }

            try {
                $plan = $this->preflightArchive($zip, $limits);
                $freeBytes = disk_free_space($artifactRoot);
                if (is_float($freeBytes)
                    && $freeBytes < $plan['unpacked_bytes'] + self::DISK_HEADROOM_BYTES) {
                    throw new RuntimeException('There is not enough free disk space to stage the spell history dataset.');
                }

                if (! mkdir($stageDirectory, 0700)) {
                    throw new RuntimeException('Unable to create the spell history installation staging directory.');
                }
                $this->extractArchive($zip, $plan['entries'], $stageDirectory, $limits, $progress);
            } finally {
                $zip->close();
            }

            $insideDescriptor = $this->decodeJsonFile(
                $stageDirectory.'/package.json',
                self::MAX_DESCRIPTOR_BYTES,
                'packaged spell history descriptor',
            );
            $insidePackage = $this->validatePackageDescriptor($insideDescriptor, false);
            if ($insidePackage['dataset'] !== $plan['dataset']) {
                throw new RuntimeException('The packaged descriptor does not match the ZIP dataset directory.');
            }
            if ($insidePackage['archive']['sha256'] !== null
                && ! hash_equals($insidePackage['archive']['sha256'], $expectedSha256)) {
                throw new RuntimeException('The packaged descriptor archive hash is inconsistent.');
            }

            if ($externalDescriptor !== null) {
                $outsidePackage = $this->validatePackageDescriptor($externalDescriptor, true);
                $this->assertSamePackageIdentity($insidePackage, $outsidePackage);
            }

            $stagedDataset = $stageDirectory.'/dataset/'.$plan['dataset'];
            $validation = $this->validateDataset($stagedDataset, $insidePackage, $progress);
            $installed = $this->mutationLock->exclusive(
                $artifactRoot,
                function () use (
                    $activate,
                    $artifactRoot,
                    $limits,
                    $plan,
                    $stagedDataset,
                    $validation,
                ): array {
                    $datasetsRoot = SafePath::existingDirectory(
                        $artifactRoot.'/datasets',
                        'Spell history datasets directory',
                    );
                    $finalDirectory = $datasetsRoot.'/'.$plan['dataset'];
                    $reused = false;

                    if (is_link($finalDirectory)) {
                        throw new RuntimeException('An installed spell history dataset cannot be a symbolic link.');
                    }
                    if (file_exists($finalDirectory)) {
                        if (! is_dir($finalDirectory)
                            || ! $this->datasetsMatch(
                                $stagedDataset,
                                $finalDirectory,
                                $validation['files'],
                                $limits['max_files'],
                            )) {
                            throw new RuntimeException(
                                'A dataset with this key already exists but its contents differ from the verified package.'
                            );
                        }
                        $reused = true;
                    } elseif (! rename($stagedDataset, $finalDirectory)) {
                        throw new RuntimeException('Unable to atomically install the staged spell history dataset.');
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

            return [
                'dataset' => $plan['dataset'],
                'reused' => $installed['reused'],
                'activated' => $activate,
                'previous_dataset' => $installed['previous'],
                'path' => SafePath::normalize($installed['path']),
                'stats' => $validation['stats'],
            ];
        } finally {
            if (is_dir($stageDirectory) || is_link($stageDirectory)) {
                $this->removePrivateDirectory($stageDirectory, $artifactRoot, '.installing-');
            }
        }
    }

    /**
     * @param  array{max_download_bytes: int, max_unpacked_bytes: int, max_files: int, connect_timeout: int, download_timeout: int}  $limits
     * @return array{dataset: string, entries: list<array{index: int, name: string, size: int}>, unpacked_bytes: int}
     */
    private function preflightArchive(ZipArchive $zip, array $limits): array
    {
        if ($zip->numFiles < 4 || $zip->numFiles > $limits['max_files'] + 1_024) {
            throw new RuntimeException('The spell history ZIP has an invalid entry count.');
        }

        $seen = [];
        $datasetKeys = [];
        $files = [];
        $unpackedBytes = 0;
        $fileCount = 0;
        $hasPackage = false;
        $hasManifest = false;
        $hasCompletion = false;
        $spellCount = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (! is_array($stat) || ! is_string($stat['name'] ?? null)) {
                throw new RuntimeException('The spell history ZIP contains an unreadable entry.');
            }
            $name = $stat['name'];
            $directory = str_ends_with($name, '/');
            $this->assertSafeArchiveName($name, $directory);
            $comparison = strtolower($name);
            if (isset($seen[$comparison])) {
                throw new RuntimeException("The spell history ZIP contains a duplicate entry: {$name}");
            }
            $seen[$comparison] = true;
            $this->assertRegularZipEntry($zip, $index, $directory, $name);

            $size = $stat['size'] ?? null;
            if (! is_int($size) || $size < 0 || ($directory && $size !== 0)) {
                throw new RuntimeException("The spell history ZIP entry has an invalid size: {$name}");
            }
            if (is_int($stat['encryption_method'] ?? null) && $stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw new RuntimeException("Encrypted ZIP entries are not supported: {$name}");
            }

            if ($directory) {
                if (! $this->allowedArchiveDirectory($name)) {
                    throw new RuntimeException("Unexpected directory in spell history ZIP: {$name}");
                }
                if (preg_match('#^dataset/([a-f0-9]{64})/#D', $name, $match) === 1) {
                    $datasetKeys[$match[1]] = true;
                }

                continue;
            }

            $fileCount++;
            if ($fileCount > $limits['max_files'] + 1) {
                throw new RuntimeException('The spell history ZIP exceeds the configured file-count limit.');
            }
            if ($size > $limits['max_unpacked_bytes'] - $unpackedBytes) {
                throw new RuntimeException('The spell history ZIP exceeds the configured unpacked-byte limit.');
            }
            $unpackedBytes += $size;

            if ($name === 'package.json') {
                $hasPackage = true;
                $this->assertEntrySize($size, self::MAX_DESCRIPTOR_BYTES, 'package descriptor');
            } elseif (preg_match('#^dataset/([a-f0-9]{64})/manifest\.json$#D', $name, $match) === 1) {
                $datasetKeys[$match[1]] = true;
                $hasManifest = true;
                $this->assertEntrySize($size, SpellHistoryArtifact::MAX_MANIFEST_BYTES, 'manifest');
            } elseif (preg_match('#^dataset/([a-f0-9]{64})/COMPLETE\.json$#D', $name, $match) === 1) {
                $datasetKeys[$match[1]] = true;
                $hasCompletion = true;
                $this->assertEntrySize($size, SpellHistoryArtifact::MAX_COMPLETION_BYTES, 'completion marker');
            } elseif (preg_match('#^dataset/([a-f0-9]{64})/spells/([a-f0-9]{2})/(0|[1-9][0-9]*)\.json$#D', $name, $match) === 1) {
                $datasetKeys[$match[1]] = true;
                $spellId = filter_var($match[3], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
                ]);
                if ($spellId === false || $match[2] !== substr(hash('sha256', (string) $spellId), 0, 2)) {
                    throw new RuntimeException("Spell artifact is in the wrong shard directory: {$name}");
                }
                $spellCount++;
                $this->assertEntrySize($size, SpellHistoryArtifact::MAX_SPELL_BYTES, 'spell artifact');
            } else {
                throw new RuntimeException("Unexpected file in spell history ZIP: {$name}");
            }

            $files[] = ['index' => $index, 'name' => $name, 'size' => $size];
        }

        $keys = array_keys($datasetKeys);
        if (! $hasPackage || ! $hasManifest || ! $hasCompletion || $spellCount < 1 || count($keys) !== 1) {
            throw new RuntimeException('The spell history ZIP does not contain exactly one complete dataset.');
        }

        return [
            'dataset' => $keys[0],
            'entries' => $files,
            'unpacked_bytes' => $unpackedBytes,
        ];
    }

    private function assertSafeArchiveName(string $name, bool $directory): void
    {
        if ($name === '' || strlen($name) > 512 || str_contains($name, "\0") || str_contains($name, '\\')
            || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            throw new RuntimeException('The spell history ZIP contains an unsafe path.');
        }

        $trimmed = $directory ? substr($name, 0, -1) : $name;
        $segments = explode('/', $trimmed);
        if ($trimmed === '' || in_array('', $segments, true)
            || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new RuntimeException("The spell history ZIP contains an unsafe path: {$name}");
        }
    }

    private function allowedArchiveDirectory(string $name): bool
    {
        return $name === 'dataset/'
            || preg_match('#^dataset/[a-f0-9]{64}/$#D', $name) === 1
            || preg_match('#^dataset/[a-f0-9]{64}/spells/$#D', $name) === 1
            || preg_match('#^dataset/[a-f0-9]{64}/spells/[a-f0-9]{2}/$#D', $name) === 1;
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

        // UNIX archives preserve the file type in the high sixteen bits. A zero
        // type is accepted for ZIP writers that omit POSIX mode information.
        if ($operatingSystem !== 3) {
            return;
        }
        $type = (($attributes >> 16) & 0xFFFF) & 0170000;
        $expected = $directory ? 0040000 : 0100000;
        if ($type !== 0 && $type !== $expected) {
            throw new RuntimeException("The spell history ZIP contains a non-regular entry: {$name}");
        }
    }

    private function assertEntrySize(int $size, int $maximum, string $label): void
    {
        if ($size < 2 || $size > $maximum) {
            throw new RuntimeException("The packaged {$label} has an invalid size.");
        }
    }

    /**
     * @param  list<array{index: int, name: string, size: int}>  $entries
     * @param  array{max_download_bytes: int, max_unpacked_bytes: int, max_files: int, connect_timeout: int, download_timeout: int}  $limits
     * @param  callable(string, array<string, mixed>): void|null  $progress
     */
    private function extractArchive(
        ZipArchive $zip,
        array $entries,
        string $stageDirectory,
        array $limits,
        ?callable $progress,
    ): void {
        $writtenTotal = 0;
        $entryTotal = count($entries);

        foreach ($entries as $position => $entry) {
            $destination = $stageDirectory.'/'.$entry['name'];
            $parent = dirname($destination);
            if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
                throw new RuntimeException("Unable to create a staging directory for {$entry['name']}.");
            }
            if (is_link($parent)) {
                throw new RuntimeException('A spell history staging directory became unsafe.');
            }

            $source = $zip->getStream($entry['name']);
            if ($source === false) {
                throw new RuntimeException("Unable to read ZIP entry {$entry['name']}.");
            }
            $target = fopen($destination, 'x+b');
            if ($target === false) {
                fclose($source);
                throw new RuntimeException("Unable to create staged file {$entry['name']}.");
            }

            $written = 0;
            try {
                while (! feof($source)) {
                    $chunk = fread($source, self::COPY_CHUNK_BYTES);
                    if ($chunk === false) {
                        throw new RuntimeException("Unable to read ZIP entry {$entry['name']}.");
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    $chunkBytes = strlen($chunk);
                    if ($chunkBytes > $entry['size'] - $written
                        || $chunkBytes > $limits['max_unpacked_bytes'] - $writtenTotal) {
                        throw new RuntimeException('A ZIP entry expanded beyond its declared or configured limit.');
                    }
                    $this->writeAll($target, $chunk, $entry['name']);
                    $written += $chunkBytes;
                    $writtenTotal += $chunkBytes;
                }
                if ($written !== $entry['size'] || ! fflush($target)) {
                    throw new RuntimeException("ZIP entry size changed while extracting {$entry['name']}.");
                }
            } finally {
                fclose($source);
                fclose($target);
            }

            if ($progress !== null && (($position + 1) === $entryTotal || ($position + 1) % 5_000 === 0)) {
                $progress('extract', [
                    'current' => $position + 1,
                    'total' => $entryTotal,
                    'bytes' => $writtenTotal,
                ]);
            }
        }
    }

    /**
     * @param  resource  $target
     */
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
     * @return array{dataset: string, artifact_format_version: int, canonical_format_version: int, first_capture: string, latest_capture: string, stats: array{snapshot_count: int, spell_count: int, revision_count: int, artifact_bytes: int, unpacked_bytes: int, file_count: int}, archive: array{name: string, sha256: ?string, bytes: ?int}}
     */
    private function validatePackageDescriptor(array $descriptor, bool $external): array
    {
        $dataset = $descriptor['dataset'] ?? null;
        $firstCapture = $descriptor['first_capture'] ?? null;
        $latestCapture = $descriptor['latest_capture'] ?? null;
        $stats = $descriptor['stats'] ?? null;
        $archive = $descriptor['archive'] ?? null;
        if (($descriptor['schema'] ?? null) !== self::PACKAGE_SCHEMA
            || ($descriptor['package_version'] ?? null) !== self::PACKAGE_VERSION
            || ! is_string($dataset)
            || preg_match(SpellHistoryArtifact::DATASET_KEY_PATTERN, $dataset) !== 1
            || ($descriptor['artifact_format_version'] ?? null) !== SpellHistoryArtifact::FORMAT_VERSION
            || ($descriptor['canonical_format_version'] ?? null) !== SpellCanonicalizer::FORMAT_VERSION
            || ! is_string($firstCapture) || ! $this->validTimestamp($firstCapture)
            || ! is_string($latestCapture) || ! $this->validTimestamp($latestCapture)
            || $latestCapture < $firstCapture
            || ! is_array($stats) || ! is_array($archive)) {
            throw new RuntimeException('The spell history package descriptor is incompatible or incomplete.');
        }

        foreach (['snapshot_count', 'spell_count', 'revision_count', 'artifact_bytes', 'unpacked_bytes', 'file_count'] as $field) {
            if (! is_int($stats[$field] ?? null) || $stats[$field] < 1) {
                throw new RuntimeException("The spell history package statistic {$field} is invalid.");
            }
        }
        if ($stats['revision_count'] < $stats['spell_count']
            || $stats['file_count'] !== $stats['spell_count'] + 2) {
            throw new RuntimeException('The spell history package file and revision counts are inconsistent.');
        }

        $archiveName = $archive['name'] ?? null;
        if (! is_string($archiveName)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D', $archiveName) !== 1) {
            throw new RuntimeException('The spell history package archive name is invalid.');
        }
        $archiveHash = $archive['sha256'] ?? null;
        $archiveBytes = $archive['bytes'] ?? null;
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
            'artifact_format_version' => SpellHistoryArtifact::FORMAT_VERSION,
            'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
            'first_capture' => $firstCapture,
            'latest_capture' => $latestCapture,
            'stats' => [
                'snapshot_count' => $stats['snapshot_count'],
                'spell_count' => $stats['spell_count'],
                'revision_count' => $stats['revision_count'],
                'artifact_bytes' => $stats['artifact_bytes'],
                'unpacked_bytes' => $stats['unpacked_bytes'],
                'file_count' => $stats['file_count'],
            ],
            'archive' => [
                'name' => $archiveName,
                'sha256' => $archiveHash,
                'bytes' => $archiveBytes,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $inside
     * @param  array<string, mixed>  $outside
     */
    private function assertSamePackageIdentity(array $inside, array $outside): void
    {
        foreach ([
            'dataset',
            'artifact_format_version',
            'canonical_format_version',
            'first_capture',
            'latest_capture',
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
     * @return array{files: list<string>, stats: array<string, int>}
     */
    private function validateDataset(string $datasetDirectory, array $package, ?callable $progress): array
    {
        $datasetRoot = SafePath::existingDirectory($datasetDirectory, 'Staged spell history dataset');
        if (is_link($datasetRoot)) {
            throw new RuntimeException('The staged spell history dataset cannot be a symbolic link.');
        }

        $dataset = $package['dataset'];
        $manifestPath = $datasetRoot.'/manifest.json';
        $manifest = $this->decodeJsonFile(
            $manifestPath,
            SpellHistoryArtifact::MAX_MANIFEST_BYTES,
            'staged spell history manifest',
        );
        $this->assertArtifactHeader($manifest, 'manifest', $dataset);
        $manifestStats = $manifest['stats'] ?? null;
        $snapshots = $manifest['snapshots'] ?? null;
        if (! is_array($manifestStats) || ! is_array($snapshots) || ! array_is_list($snapshots) || $snapshots === []) {
            throw new RuntimeException('The staged spell history manifest is incomplete.');
        }

        $snapshotTimes = [];
        $snapshotIndexes = [];
        $previousTime = null;
        foreach ($snapshots as $index => $snapshot) {
            $key = is_array($snapshot) ? ($snapshot['key'] ?? null) : null;
            $observedAt = is_array($snapshot) ? ($snapshot['observed_at'] ?? null) : null;
            $expectedPrevious = $index > 0 ? $snapshots[$index - 1]['key'] : null;
            if (! is_string($key) || preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $key) !== 1
                || isset($snapshotTimes[$key]) || ! is_string($observedAt) || ! $this->validTimestamp($observedAt)
                || ($snapshot['sequence'] ?? null) !== $index + 1
                || ! is_int($snapshot['source_physical_line_count'] ?? null)
                || $snapshot['source_physical_line_count'] < 1
                || ! in_array($snapshot['health_status'] ?? null, ['healthy', 'anomalously_low_record_count'], true)
                || ! is_bool($snapshot['trusted_for_absence_confirmation'] ?? null)
                || ($snapshot['previous_snapshot'] ?? null) !== $expectedPrevious
                || ($previousTime !== null && $observedAt <= $previousTime)) {
                throw new RuntimeException('The staged spell history manifest contains an invalid snapshot.');
            }
            $snapshotTimes[$key] = $observedAt;
            $snapshotIndexes[$key] = $index;
            $previousTime = $observedAt;
        }

        $firstCapture = $snapshots[0]['observed_at'];
        $latestCapture = $snapshots[array_key_last($snapshots)]['observed_at'];
        if ($package['first_capture'] !== $firstCapture || $package['latest_capture'] !== $latestCapture) {
            throw new RuntimeException('The package capture range does not match the manifest.');
        }
        foreach (['snapshot_count', 'spell_count', 'revision_count', 'artifact_bytes'] as $field) {
            if (($manifestStats[$field] ?? null) !== $package['stats'][$field]) {
                throw new RuntimeException("The package {$field} does not match the manifest.");
            }
        }
        if ($manifestStats['snapshot_count'] !== count($snapshots)) {
            throw new RuntimeException('The manifest snapshot count is inconsistent.');
        }

        $completionPath = $datasetRoot.'/COMPLETE.json';
        $completion = $this->decodeJsonFile(
            $completionPath,
            SpellHistoryArtifact::MAX_COMPLETION_BYTES,
            'staged spell history completion marker',
        );
        $this->assertArtifactHeader($completion, 'completion', $dataset);
        $manifestBytes = filesize($manifestPath);
        $manifestHash = hash_file('sha256', $manifestPath);
        if ($manifestBytes === false || $manifestHash === false
            || ($completion['manifest_bytes'] ?? null) !== $manifestBytes
            || ! is_string($completion['manifest_sha256'] ?? null)
            || ! hash_equals($manifestHash, $completion['manifest_sha256'])
            || ($completion['spell_count'] ?? null) !== $package['stats']['spell_count']
            || ($completion['revision_count'] ?? null) !== $package['stats']['revision_count']) {
            throw new RuntimeException('The staged spell history completion marker is invalid.');
        }

        $files = ['manifest.json', 'COMPLETE.json'];
        $spellFiles = $this->enumerateSpellFiles($datasetRoot, $package['stats']['spell_count']);
        $revisionCount = 0;
        $artifactBytes = $manifestBytes;
        foreach ($spellFiles as $index => $relativePath) {
            $path = $datasetRoot.'/'.$relativePath;
            $spell = $this->decodeJsonFile($path, SpellHistoryArtifact::MAX_SPELL_BYTES, "spell artifact {$relativePath}");
            $spellId = filter_var(pathinfo($path, PATHINFO_FILENAME), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
            ]);
            if ($spellId === false) {
                throw new RuntimeException("The packaged spell artifact path {$relativePath} is invalid.");
            }
            $this->assertArtifactHeader($spell, 'spell', $dataset);
            $revisions = $spell['revisions'] ?? null;
            $absenceRanges = $spell['absence_ranges'] ?? [];
            $latestPresenceStatus = $spell['latest_presence_status'] ?? null;
            $latestIcon = $spell['latest_icon'] ?? null;
            if (($spell['spell_id'] ?? null) !== $spellId || ! is_array($revisions) || ! array_is_list($revisions)
                || $revisions === [] || ! is_array($absenceRanges) || ! array_is_list($absenceRanges)
                || ($spell['revision_count'] ?? null) !== count($revisions)
                || ! is_string($spell['first_observed_at'] ?? null)
                || ! $this->validTimestamp($spell['first_observed_at'])
                || ! is_string($spell['last_observed_at'] ?? null)
                || ! $this->validTimestamp($spell['last_observed_at'])
                || $spell['last_observed_at'] < $spell['first_observed_at']
                || ! is_bool($spell['present_in_latest_snapshot'] ?? null)
                || ($latestPresenceStatus !== null
                    && ! in_array($latestPresenceStatus, ['present', 'not_observed', 'uncertain_single_capture_gap'], true))
                || ($latestPresenceStatus === 'present' && ! $spell['present_in_latest_snapshot'])
                || ($latestPresenceStatus !== null && $latestPresenceStatus !== 'present'
                    && $spell['present_in_latest_snapshot'])
                || (! is_null($spell['latest_name'] ?? null) && ! is_string($spell['latest_name']))
                || ($latestIcon !== null && (! is_int($latestIcon) || $latestIcon < 0))) {
                throw new RuntimeException("The packaged spell artifact {$spellId} is inconsistent.");
            }
            $previousRevisionTime = null;
            foreach ($revisions as $revisionIndex => $revision) {
                $snapshot = is_array($revision) ? ($revision['snapshot'] ?? null) : null;
                $observedAt = is_array($revision) ? ($revision['observed_at'] ?? null) : null;
                if (! is_array($revision) || ! is_string($snapshot) || ! isset($snapshotTimes[$snapshot])
                    || $observedAt !== $snapshotTimes[$snapshot]
                    || ! in_array($revision['type'] ?? null, [
                        'first_observed', 'changed', 'presence_missing', 'presence_restored',
                    ], true)
                    || ! is_array($revision['groups'] ?? null) || ! array_is_list($revision['groups'])) {
                    throw new RuntimeException("The packaged spell artifact {$spellId} has an invalid revision.");
                }
                if (($revisionIndex === 0) !== ($revision['type'] === 'first_observed')) {
                    throw new RuntimeException("The packaged spell artifact {$spellId} has an invalid first revision.");
                }
                $snapshotIndex = $snapshotIndexes[$snapshot];
                $expectedPrevious = $snapshotIndex > 0 ? $snapshots[$snapshotIndex - 1]['key'] : null;
                if (($revision['previous_snapshot'] ?? null) !== $expectedPrevious
                    || ($previousRevisionTime !== null && $observedAt < $previousRevisionTime)) {
                    throw new RuntimeException("The packaged spell artifact {$spellId} has an invalid revision interval.");
                }
                foreach ($revision['groups'] as $group) {
                    $this->validateChangeGroup($group, $spellId);
                }
                $previousRevisionTime = $observedAt;
            }
            if ($revisions[0]['observed_at'] !== $spell['first_observed_at']) {
                throw new RuntimeException("The packaged spell artifact {$spellId} has an inconsistent first observation.");
            }

            $previousRangeEnd = -1;
            foreach ($absenceRanges as $range) {
                $firstKey = is_array($range) ? ($range['first_snapshot'] ?? null) : null;
                $lastKey = is_array($range) ? ($range['last_snapshot'] ?? null) : null;
                $captures = is_array($range) ? ($range['captures'] ?? null) : null;
                $trustedCaptures = is_array($range) ? ($range['trusted_captures'] ?? null) : null;
                if (! is_string($firstKey) || ! is_string($lastKey)
                    || ! isset($snapshotIndexes[$firstKey], $snapshotIndexes[$lastKey])
                    || ! is_int($captures) || $captures < 1
                    || ! is_int($trustedCaptures) || $trustedCaptures < 0 || $trustedCaptures > $captures
                    || ($range['confirmed'] ?? null) !== ($trustedCaptures >= 2)) {
                    throw new RuntimeException("The packaged spell artifact {$spellId} has an invalid absence range.");
                }
                $firstIndex = $snapshotIndexes[$firstKey];
                $lastIndex = $snapshotIndexes[$lastKey];
                $expectedTrustedCaptures = count(array_filter(
                    array_slice($snapshots, $firstIndex, $lastIndex - $firstIndex + 1),
                    static fn (array $entry): bool => $entry['trusted_for_absence_confirmation'],
                ));
                if ($firstIndex <= $previousRangeEnd || $lastIndex < $firstIndex
                    || $captures !== $lastIndex - $firstIndex + 1
                    || $trustedCaptures !== $expectedTrustedCaptures
                    || ($range['first_observed_at'] ?? null) !== $snapshots[$firstIndex]['observed_at']
                    || ($range['last_observed_at'] ?? null) !== $snapshots[$lastIndex]['observed_at']) {
                    throw new RuntimeException("The packaged spell artifact {$spellId} has an inconsistent absence range.");
                }
                $previousRangeEnd = $lastIndex;
            }
            $bytes = filesize($path);
            if ($bytes === false) {
                throw new RuntimeException("Unable to size spell artifact {$spellId}.");
            }
            $artifactBytes += $bytes;
            $revisionCount += count($revisions);
            $files[] = $relativePath;

            if ($progress !== null && (($index + 1) === count($spellFiles) || ($index + 1) % 5_000 === 0)) {
                $progress('validate', ['current' => $index + 1, 'total' => count($spellFiles)]);
            }
        }

        $completionBytes = filesize($completionPath);
        if ($completionBytes === false) {
            throw new RuntimeException('Unable to size the spell history completion marker.');
        }
        if (count($spellFiles) !== $package['stats']['spell_count']
            || count($files) !== $package['stats']['file_count']
            || $revisionCount !== $package['stats']['revision_count']
            || $artifactBytes !== $package['stats']['artifact_bytes']
            || $artifactBytes + $completionBytes !== $package['stats']['unpacked_bytes']) {
            throw new RuntimeException('The packaged dataset totals do not match its descriptor.');
        }

        return [
            'files' => $files,
            'stats' => [
                'snapshot_count' => count($snapshots),
                'spell_count' => count($spellFiles),
                'revision_count' => $revisionCount,
                'artifact_bytes' => $artifactBytes,
                'unpacked_bytes' => $artifactBytes + $completionBytes,
                'file_count' => count($files),
            ],
        ];
    }

    private function validateChangeGroup(mixed $group, int $spellId): void
    {
        if (! is_array($group)
            || ! is_string($group['key'] ?? null)
            || ! is_string($group['section'] ?? null)
            || ! is_string($group['label'] ?? null)
            || ! is_array($group['changes'] ?? null)
            || ! array_is_list($group['changes'])) {
            throw new RuntimeException("The packaged spell artifact {$spellId} has an invalid change group.");
        }
        foreach ($group['changes'] as $change) {
            if (! is_array($change)
                || ! is_string($change['field'] ?? null)
                || ! is_string($change['label'] ?? null)
                || ! array_key_exists('old', $change)
                || ! array_key_exists('new', $change)) {
                throw new RuntimeException("The packaged spell artifact {$spellId} has an invalid change.");
            }
        }
    }

    /** @return list<string> */
    private function enumerateSpellFiles(string $datasetRoot, int $maximumFiles): array
    {
        $spellsRoot = $datasetRoot.'/spells';
        if (is_link($spellsRoot) || ! is_dir($spellsRoot)) {
            throw new RuntimeException('The packaged dataset has no safe spells directory.');
        }

        $files = [];
        $shards = scandir($spellsRoot);
        if ($shards === false) {
            throw new RuntimeException('Unable to enumerate packaged spell shards.');
        }
        foreach ($shards as $shard) {
            if ($shard === '.' || $shard === '..') {
                continue;
            }
            $shardPath = $spellsRoot.'/'.$shard;
            if (preg_match('/^[a-f0-9]{2}$/D', $shard) !== 1 || is_link($shardPath) || ! is_dir($shardPath)) {
                throw new RuntimeException('The packaged dataset contains an unsafe spell shard.');
            }
            $entries = scandir($shardPath);
            if ($entries === false) {
                throw new RuntimeException("Unable to enumerate packaged spell shard {$shard}.");
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $shardPath.'/'.$entry;
                if (preg_match('/^(0|[1-9][0-9]*)\.json$/D', $entry, $match) !== 1
                    || is_link($path) || ! is_file($path)) {
                    throw new RuntimeException("The packaged spell shard {$shard} contains an unexpected entry.");
                }
                $spellId = filter_var($match[1], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
                ]);
                if ($spellId === false || $shard !== substr(hash('sha256', (string) $spellId), 0, 2)) {
                    throw new RuntimeException("Packaged spell {$spellId} is in the wrong shard.");
                }
                $files[] = "spells/{$shard}/{$entry}";
                if (count($files) > $maximumFiles) {
                    throw new RuntimeException('The packaged dataset contains more spells than declared.');
                }
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  list<string>  $expectedFiles
     */
    private function datasetsMatch(string $staged, string $installed, array $expectedFiles, int $maximumFiles): bool
    {
        $actual = $this->enumerateDatasetFiles($installed, $maximumFiles);
        $expected = $expectedFiles;
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            return false;
        }

        foreach ($expected as $relative) {
            $left = $staged.'/'.$relative;
            $right = $installed.'/'.$relative;
            if (is_link($right) || ! is_file($right) || filesize($left) !== filesize($right)) {
                return false;
            }
            $leftHash = hash_file('sha256', $left);
            $rightHash = hash_file('sha256', $right);
            if ($leftHash === false || $rightHash === false || ! hash_equals($leftHash, $rightHash)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function enumerateDatasetFiles(string $root, int $maximumFiles): array
    {
        if (is_link($root) || ! is_dir($root)) {
            return [];
        }
        $files = [];
        $walk = function (string $directory, string $prefix) use (&$walk, &$files, $maximumFiles): void {
            $entries = scandir($directory);
            if ($entries === false) {
                throw new RuntimeException('Unable to enumerate an installed dataset.');
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory.'/'.$entry;
                $relative = $prefix === '' ? $entry : $prefix.'/'.$entry;
                if (is_link($path)) {
                    throw new RuntimeException('An installed dataset contains a symbolic link.');
                }
                if (is_file($path)) {
                    $files[] = $relative;
                    if (count($files) > $maximumFiles) {
                        throw new RuntimeException('An installed dataset exceeds the file-count limit.');
                    }
                } elseif (is_dir($path)) {
                    $walk($path, $relative);
                } else {
                    throw new RuntimeException('An installed dataset contains a non-regular entry.');
                }
            }
        };
        $walk($root, '');
        sort($files, SORT_STRING);

        return $files;
    }

    /** @param array<string, mixed> $artifact */
    private function assertArtifactHeader(array $artifact, string $type, string $dataset): void
    {
        if (($artifact['schema'] ?? null) !== SpellHistoryArtifact::SCHEMA
            || ($artifact['artifact_type'] ?? null) !== $type
            || ($artifact['format_version'] ?? null) !== SpellHistoryArtifact::FORMAT_VERSION
            || ($artifact['canonical_format_version'] ?? null) !== SpellCanonicalizer::FORMAT_VERSION
            || ($artifact['dataset'] ?? null) !== $dataset) {
            throw new RuntimeException("The packaged {$type} artifact has an incompatible format.");
        }
    }

    /** @return array<string, mixed> */
    private function githubRelease(string $repository, string $tag, array $limits): array
    {
        $url = 'https://api.github.com/repos/'.$repository.'/releases/tags/'.rawurlencode($tag);
        $response = Http::acceptJson()
            ->withHeaders(['User-Agent' => 'modern-allaclone-spell-history-installer'])
            ->connectTimeout($limits['connect_timeout'])
            ->timeout(min($limits['download_timeout'], 120))
            ->withOptions([
                'on_headers' => function (ResponseInterface $response): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && ctype_digit($length) && (int) $length > self::MAX_RELEASE_METADATA_BYTES) {
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
        if (! is_string($url) || ! is_int($declaredBytes) || $declaredBytes < 1 || $declaredBytes > $maximumBytes) {
            throw new RuntimeException('GitHub release asset metadata is invalid or exceeds the configured limit.');
        }
        $this->assertGithubDownloadUrl($url, $repository);
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('Refusing to overwrite an existing download file.');
        }

        try {
            $response = Http::accept('*/*')
                ->withHeaders(['User-Agent' => 'modern-allaclone-spell-history-installer'])
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
            || ! str_starts_with(rawurldecode($path), '/'.$repository.'/releases/download/')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
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
            'max_download_bytes' => $limits['max_download_bytes'] ?? 1_610_612_736,
            'max_unpacked_bytes' => $limits['max_unpacked_bytes'] ?? 1_610_612_736,
            'max_files' => $limits['max_files'] ?? 100_000,
            'connect_timeout' => $limits['connect_timeout'] ?? 15,
            'download_timeout' => $limits['download_timeout'] ?? 1_800,
        ];
        foreach ($resolved as $name => $value) {
            if (! is_int($value) || $value < 1) {
                throw new RuntimeException("Spell history installer limit {$name} must be a positive integer.");
            }
        }
        if ($resolved['max_files'] > 1_000_000 || $resolved['connect_timeout'] > 300
            || $resolved['download_timeout'] > 86_400) {
            throw new RuntimeException('Spell history installer limits exceed their safety ceilings.');
        }

        return $resolved;
    }

    private function safeLocalArchive(string $path): string
    {
        if (! str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\/]/', $path) !== 1
            && ! str_starts_with($path, '\\\\')) {
            throw new RuntimeException('Spell history ZIP path must be absolute.');
        }
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('Spell history ZIP is missing or is not a regular file.');
        }
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException('Unable to resolve the spell history ZIP path.');
        }

        return SafePath::normalize($resolved);
    }

    private function prepareArtifactRoot(string $artifactDirectory): string
    {
        $root = SafePath::createDirectory($artifactDirectory, 'Spell history artifact root');
        $datasetsPath = $root.'/datasets';
        if (is_link($datasetsPath)) {
            throw new RuntimeException('Spell history datasets directory cannot be a symbolic link.');
        }
        $datasets = SafePath::createDirectory($datasetsPath, 'Spell history datasets directory');
        SafePath::assertContained($datasets, $root, 'Spell history datasets directory');

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

    private function validTimestamp(string $timestamp): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D', $timestamp) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $timestamp, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d\TH:i:s') === $timestamp;
    }

    private function removePrivateDirectory(string $directory, string $artifactRoot, string $requiredPrefix): void
    {
        if (! file_exists($directory) && ! is_link($directory)) {
            return;
        }
        if (is_link($directory)) {
            throw new RuntimeException('Refusing to recurse into a symbolic-link spell history work directory.');
        }
        $resolved = SafePath::assertContained($directory, $artifactRoot, 'Spell history work directory');
        if (dirname($resolved) !== SafePath::normalize($artifactRoot)
            || ! str_starts_with(basename($resolved), $requiredPrefix)) {
            throw new RuntimeException('Refusing to remove an unexpected spell history directory.');
        }
        $this->removeDirectoryTree($resolved, $resolved);
    }

    private function removeDirectoryTree(string $directory, string $root): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Unable to enumerate a spell history work directory.');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_link($path) || is_file($path)) {
                if (! unlink($path)) {
                    throw new RuntimeException('Unable to remove a staged spell history file.');
                }
            } elseif (is_dir($path)) {
                $child = SafePath::assertContained($path, $root, 'Spell history work directory child');
                $this->removeDirectoryTree($child, $root);
            } else {
                throw new RuntimeException('A spell history work directory contains an unsafe entry.');
            }
        }
        if (! rmdir($directory)) {
            throw new RuntimeException('Unable to remove a spell history work directory.');
        }
    }
}

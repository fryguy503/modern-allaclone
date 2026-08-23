<?php

namespace App\Services\SpellHistory;

use DateTimeImmutable;
use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class SpellHistoryPackager
{
    public const PACKAGE_SCHEMA = 'modern-allaclone.spell-history-package';

    public const PACKAGE_VERSION = 1;

    public const RELEASE_DESCRIPTOR = 'spell-history-package.json';

    private const MAX_PACKAGE_FILES = 250_000;

    private const MAX_DATASET_BYTES = 8_589_934_592;

    private const MAX_RELEASE_ASSET_BYTES = 2_000_000_000;

    public function __construct(private readonly SpellHistoryMutationLock $mutationLock) {}

    /**
     * @param  (callable(string, array<string, int|string>): void)|null  $progress
     * @return array{
     *     dataset: string,
     *     archive_name: string,
     *     zip_path: string,
     *     checksum_path: string,
     *     descriptor_path: string,
     *     sha256: string,
     *     archive_bytes: int,
     *     stats: array{snapshot_count: int, spell_count: int, revision_count: int, artifact_bytes: int, unpacked_bytes: int, file_count: int}
     * }
     */
    public function create(
        string $artifactDirectory,
        string $outputDirectory,
        ?string $archiveName = null,
        ?callable $progress = null,
    ): array {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to package spell history releases.');
        }
        if (is_link($artifactDirectory)) {
            throw new RuntimeException('Spell history artifact root cannot be a symbolic link.');
        }

        $artifactRoot = SafePath::existingDirectory($artifactDirectory, 'Spell history artifact root');
        if (is_link($outputDirectory)) {
            throw new RuntimeException('Spell history package output cannot be a symbolic link.');
        }
        $outputRoot = SafePath::createDirectory($outputDirectory, 'Spell history package output');

        return $this->withOutputLock(
            $outputRoot,
            fn (): array => $this->mutationLock->shared(
                $artifactRoot,
                fn (): array => $this->createLocked(
                    $artifactRoot,
                    $outputRoot,
                    $archiveName,
                    $progress,
                ),
            ),
        );
    }

    /**
     * @param  (callable(string, array<string, int|string>): void)|null  $progress
     * @return array<string, mixed>
     */
    private function createLocked(
        string $artifactRoot,
        string $outputRoot,
        ?string $requestedArchiveName,
        ?callable $progress,
    ): array {
        $datasetKey = $this->readCurrentPointer($artifactRoot);
        $datasetsPath = $artifactRoot.'/datasets';
        if (is_link($datasetsPath)) {
            throw new RuntimeException('Spell history datasets directory cannot be a symbolic link.');
        }
        $datasetsRoot = SafePath::existingDirectory($datasetsPath, 'Spell history datasets directory');
        SafePath::assertContained($datasetsRoot, $artifactRoot, 'Spell history datasets directory');

        $datasetPath = $datasetsRoot.'/'.$datasetKey;
        if (is_link($datasetPath)) {
            throw new RuntimeException('The active spell history dataset cannot be a symbolic link.');
        }
        $datasetRoot = SafePath::existingDirectory($datasetPath, 'Active spell history dataset');
        SafePath::assertContained($datasetRoot, $datasetsRoot, 'Active spell history dataset');
        if (SafePath::pathsOverlap($datasetRoot, $outputRoot)) {
            throw new RuntimeException('Spell history package output cannot overlap the active dataset.');
        }

        $validated = $this->validateDataset($datasetRoot, $datasetKey, $progress);
        $archiveName = $this->archiveName(
            $requestedArchiveName,
            $validated['format_version'],
            $validated['canonical_format_version'],
            $validated['latest_capture'],
            $datasetKey,
        );

        $zipPath = $outputRoot.'/'.$archiveName;
        $checksumPath = $zipPath.'.sha256';
        $descriptorPath = $outputRoot.'/'.self::RELEASE_DESCRIPTOR;
        foreach ([$zipPath, $checksumPath, $descriptorPath] as $releasePath) {
            if (file_exists($releasePath) || is_link($releasePath)) {
                throw new RuntimeException("Refusing to overwrite an existing release asset: {$releasePath}");
            }
        }

        $inArchiveDescriptor = $this->descriptor($validated, [
            'name' => $archiveName,
            'sha256' => null,
            'bytes' => null,
        ]);
        $packageJson = $this->encodeJson($inArchiveDescriptor, 'spell history package metadata');
        $packageRecord = [
            'entry' => 'package.json',
            'path' => null,
            'contents' => $packageJson,
            'bytes' => strlen($packageJson),
            'sha256' => hash('sha256', $packageJson),
        ];

        $temporaryZip = $outputRoot.'/.spell-history-'.bin2hex(random_bytes(12)).'.zip.tmp';
        $temporaryChecksum = $outputRoot.'/.spell-history-'.bin2hex(random_bytes(12)).'.sha256.tmp';
        $temporaryDescriptor = $outputRoot.'/.spell-history-'.bin2hex(random_bytes(12)).'.json.tmp';
        $published = [];

        try {
            $this->writeArchive(
                $temporaryZip,
                $datasetKey,
                $packageRecord,
                $validated['records'],
                $progress,
            );
            $this->verifyArchive(
                $temporaryZip,
                $datasetKey,
                $packageRecord,
                $validated['records'],
            );

            clearstatcache(true, $temporaryZip);
            $archiveBytes = filesize($temporaryZip);
            $archiveHash = hash_file('sha256', $temporaryZip);
            if ($archiveBytes === false || $archiveBytes < 1 || $archiveBytes > self::MAX_RELEASE_ASSET_BYTES) {
                throw new RuntimeException('The spell history ZIP is empty or exceeds the supported GitHub release asset size.');
            }
            if (! is_string($archiveHash) || preg_match(SpellHistoryArtifact::DATASET_KEY_PATTERN, $archiveHash) !== 1) {
                throw new RuntimeException('Unable to hash the completed spell history ZIP.');
            }

            $releaseDescriptor = $this->descriptor($validated, [
                'name' => $archiveName,
                'sha256' => $archiveHash,
                'bytes' => $archiveBytes,
            ]);
            $this->writeNewFile(
                $temporaryChecksum,
                "{$archiveHash}  {$archiveName}\n",
                'spell history checksum sidecar',
            );
            $this->writeNewFile(
                $temporaryDescriptor,
                $this->encodeJson($releaseDescriptor, 'spell history release descriptor'),
                'spell history release descriptor',
            );

            foreach ([
                [$temporaryZip, $zipPath],
                [$temporaryChecksum, $checksumPath],
                [$temporaryDescriptor, $descriptorPath],
            ] as [$temporaryPath, $releasePath]) {
                $this->publishNoClobber($temporaryPath, $releasePath);
                $published[] = $releasePath;
            }

            return [
                'dataset' => $datasetKey,
                'archive_name' => $archiveName,
                'zip_path' => SafePath::normalize($zipPath),
                'checksum_path' => SafePath::normalize($checksumPath),
                'descriptor_path' => SafePath::normalize($descriptorPath),
                'sha256' => $archiveHash,
                'archive_bytes' => $archiveBytes,
                'stats' => $releaseDescriptor['stats'],
            ];
        } catch (Throwable $exception) {
            foreach ([$temporaryZip, $temporaryChecksum, $temporaryDescriptor, ...$published] as $path) {
                if (is_file($path) && ! is_link($path)) {
                    @unlink($path);
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  (callable(string, array<string, int|string>): void)|null  $progress
     * @return array{
     *     dataset: string,
     *     format_version: int,
     *     canonical_format_version: int,
     *     first_capture: string,
     *     latest_capture: string,
     *     stats: array{snapshot_count: int, spell_count: int, revision_count: int, artifact_bytes: int, unpacked_bytes: int, file_count: int},
     *     records: list<array{relative: string, path: string, bytes: int, sha256: string}>
     * }
     */
    private function validateDataset(string $datasetRoot, string $datasetKey, ?callable $progress): array
    {
        $this->assertExactEntries($datasetRoot, ['COMPLETE.json', 'manifest.json', 'spells'], 'active dataset');

        $manifestRecord = $this->readJsonRecord(
            $datasetRoot.'/manifest.json',
            'manifest.json',
            SpellHistoryArtifact::MAX_MANIFEST_BYTES,
            'spell history manifest',
            $datasetRoot,
        );
        $completionRecord = $this->readJsonRecord(
            $datasetRoot.'/COMPLETE.json',
            'COMPLETE.json',
            SpellHistoryArtifact::MAX_COMPLETION_BYTES,
            'spell history completion marker',
            $datasetRoot,
        );
        $manifest = $manifestRecord['decoded'];
        $completion = $completionRecord['decoded'];
        $this->assertArtifactHeader($manifest, 'manifest', $datasetKey);
        $this->assertArtifactHeader($completion, 'completion', $datasetKey);

        $snapshots = $manifest['snapshots'] ?? null;
        $stats = $manifest['stats'] ?? null;
        if (! is_array($snapshots) || ! array_is_list($snapshots) || $snapshots === [] || ! is_array($stats)) {
            throw new RuntimeException('The active spell history manifest is missing snapshots or stats.');
        }
        $snapshotCount = $stats['snapshot_count'] ?? null;
        $spellCount = $stats['spell_count'] ?? null;
        $revisionCount = $stats['revision_count'] ?? null;
        $artifactBytes = $stats['artifact_bytes'] ?? null;
        if (! is_int($snapshotCount) || $snapshotCount !== count($snapshots)
            || ! is_int($spellCount) || $spellCount < 1 || $spellCount > self::MAX_PACKAGE_FILES - 2
            || ! is_int($revisionCount) || $revisionCount < $spellCount
            || ! is_int($artifactBytes) || $artifactBytes < 1 || $artifactBytes > self::MAX_DATASET_BYTES) {
            throw new RuntimeException('The active spell history manifest stats are invalid.');
        }
        [$firstCapture, $latestCapture] = $this->validateSnapshots($snapshots);

        if (($completion['manifest_sha256'] ?? null) !== $manifestRecord['sha256']
            || ($completion['manifest_bytes'] ?? null) !== $manifestRecord['bytes']
            || ($completion['spell_count'] ?? null) !== $spellCount
            || ($completion['revision_count'] ?? null) !== $revisionCount) {
            throw new RuntimeException('The active spell history completion marker does not match its manifest.');
        }

        $spellsPath = $datasetRoot.'/spells';
        if (is_link($spellsPath)) {
            throw new RuntimeException('The active spell history spells directory cannot be a symbolic link.');
        }
        $spellsRoot = SafePath::existingDirectory($spellsPath, 'Active spell history spells directory');
        SafePath::assertContained($spellsRoot, $datasetRoot, 'Active spell history spells directory');

        $spellRecords = [];
        $spellPayloadBytes = 0;
        $actualRevisionCount = 0;
        foreach ($this->directoryEntries($spellsRoot, 'spell shard directory') as $shard) {
            if (preg_match('/^[a-f0-9]{2}$/D', $shard) !== 1) {
                throw new RuntimeException("Unexpected entry in spell shard directory: {$shard}");
            }
            $shardPath = $spellsRoot.'/'.$shard;
            if (is_link($shardPath) || ! is_dir($shardPath)) {
                throw new RuntimeException("Spell history shard {$shard} must be a regular directory.");
            }
            $resolvedShard = SafePath::assertContained($shardPath, $spellsRoot, "Spell history shard {$shard}");
            $spellFiles = $this->directoryEntries($resolvedShard, "spell history shard {$shard}");
            if ($spellFiles === []) {
                throw new RuntimeException("Spell history shard {$shard} cannot be empty.");
            }

            foreach ($spellFiles as $filename) {
                if (preg_match('/^(0|[1-9][0-9]*)\.json$/D', $filename, $matches) !== 1) {
                    throw new RuntimeException("Unexpected entry in spell history shard {$shard}: {$filename}");
                }
                $spellId = filter_var($matches[1], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
                ]);
                $relative = "spells/{$shard}/{$filename}";
                if ($spellId === false || SpellHistoryArtifact::spellRelativePath($spellId) !== $relative) {
                    throw new RuntimeException("Spell history artifact has an invalid shard path: {$relative}");
                }

                $record = $this->readJsonRecord(
                    $resolvedShard.'/'.$filename,
                    $relative,
                    SpellHistoryArtifact::MAX_SPELL_BYTES,
                    "spell history artifact {$spellId}",
                    $datasetRoot,
                );
                $spell = $record['decoded'];
                $this->assertArtifactHeader($spell, 'spell', $datasetKey);
                $revisions = $spell['revisions'] ?? null;
                if (($spell['spell_id'] ?? null) !== $spellId
                    || ! is_array($revisions) || ! array_is_list($revisions) || $revisions === []
                    || ($spell['revision_count'] ?? null) !== count($revisions)) {
                    throw new RuntimeException("Spell history artifact {$spellId} is inconsistent.");
                }

                unset($record['decoded']);
                $spellRecords[] = $record;
                $spellPayloadBytes += $record['bytes'];
                $actualRevisionCount += count($revisions);
                $validatedCount = count($spellRecords);
                if ($validatedCount > $spellCount || $spellPayloadBytes > self::MAX_DATASET_BYTES) {
                    throw new RuntimeException('The active spell history dataset exceeds its declared limits.');
                }
                if ($progress !== null && ($validatedCount === 1 || $validatedCount % 5_000 === 0)) {
                    $progress('validate', ['current' => $validatedCount, 'total' => $spellCount]);
                }
            }
        }

        if (count($spellRecords) !== $spellCount || $actualRevisionCount !== $revisionCount) {
            throw new RuntimeException('Spell history artifact counts do not match the active manifest.');
        }
        if ($artifactBytes !== $manifestRecord['bytes'] + $spellPayloadBytes) {
            throw new RuntimeException('Spell history artifact bytes do not match the active manifest.');
        }
        if ($progress !== null) {
            $progress('validate', ['current' => $spellCount, 'total' => $spellCount]);
        }

        unset($manifestRecord['decoded'], $completionRecord['decoded']);

        return [
            'dataset' => $datasetKey,
            'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
            'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
            'first_capture' => $firstCapture,
            'latest_capture' => $latestCapture,
            'stats' => [
                'snapshot_count' => $snapshotCount,
                'spell_count' => $spellCount,
                'revision_count' => $revisionCount,
                'artifact_bytes' => $artifactBytes,
                'unpacked_bytes' => $artifactBytes + $completionRecord['bytes'],
                'file_count' => $spellCount + 2,
            ],
            'records' => [$manifestRecord, $completionRecord, ...$spellRecords],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @return array{string, string}
     */
    private function validateSnapshots(array $snapshots): array
    {
        $previousKey = null;
        $previousCapture = null;
        foreach ($snapshots as $index => $snapshot) {
            $key = is_array($snapshot) ? ($snapshot['key'] ?? null) : null;
            $capture = is_array($snapshot) ? ($snapshot['observed_at'] ?? null) : null;
            $parsed = is_string($capture)
                ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $capture)
                : false;
            if (! is_array($snapshot)
                || ($snapshot['sequence'] ?? null) !== $index + 1
                || ! is_string($key) || preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $key) !== 1
                || ($snapshot['previous_snapshot'] ?? null) !== $previousKey
                || ! is_string($capture) || $parsed === false || $parsed->format('Y-m-d\TH:i:s') !== $capture
                || ($previousCapture !== null && $capture <= $previousCapture)) {
                throw new RuntimeException('The active spell history manifest contains an invalid snapshot entry.');
            }
            $previousKey = $key;
            $previousCapture = $capture;
        }

        return [
            $snapshots[0]['observed_at'],
            $snapshots[array_key_last($snapshots)]['observed_at'],
        ];
    }

    /**
     * @return array{relative: string, path: string, bytes: int, sha256: string, decoded: array<string, mixed>}
     */
    private function readJsonRecord(
        string $path,
        string $relative,
        int $maximumBytes,
        string $label,
        string $datasetRoot,
    ): array {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException("Missing or unsafe {$label}.");
        }
        $resolved = SafePath::assertContained($path, $datasetRoot, ucfirst($label));
        clearstatcache(true, $resolved);
        $bytes = filesize($resolved);
        if ($bytes === false || $bytes < 2 || $bytes > $maximumBytes) {
            throw new RuntimeException("Invalid {$label} size.");
        }
        $json = file_get_contents($resolved);
        if ($json === false || strlen($json) !== $bytes) {
            throw new RuntimeException("Unable to read a stable {$label}.");
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid JSON in {$label}.", 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid {$label} payload.");
        }

        return [
            'relative' => $relative,
            'path' => $resolved,
            'bytes' => $bytes,
            'sha256' => hash('sha256', $json),
            'decoded' => $decoded,
        ];
    }

    /** @param array<string, mixed> $artifact */
    private function assertArtifactHeader(array $artifact, string $type, string $datasetKey): void
    {
        if (($artifact['schema'] ?? null) !== SpellHistoryArtifact::SCHEMA
            || ($artifact['artifact_type'] ?? null) !== $type
            || ($artifact['format_version'] ?? null) !== SpellHistoryArtifact::FORMAT_VERSION
            || ($artifact['canonical_format_version'] ?? null) !== SpellCanonicalizer::FORMAT_VERSION
            || ($artifact['dataset'] ?? null) !== $datasetKey) {
            throw new RuntimeException("The active spell history {$type} has an incompatible format.");
        }
    }

    /** @param list<string> $expected */
    private function assertExactEntries(string $directory, array $expected, string $label): void
    {
        $entries = $this->directoryEntries($directory, $label);
        sort($expected, SORT_STRING);
        if ($entries !== $expected) {
            throw new RuntimeException("The {$label} contains missing or unexpected entries.");
        }
    }

    /** @return list<string> */
    private function directoryEntries(string $directory, string $label): array
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException("Unable to enumerate {$label}.");
        }
        $entries = array_values(array_filter(
            $entries,
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
        sort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @param  array{entry: string, path: null, contents: string, bytes: int, sha256: string}  $packageRecord
     * @param  list<array{relative: string, path: string, bytes: int, sha256: string}>  $records
     * @param  (callable(string, array<string, int|string>): void)|null  $progress
     */
    private function writeArchive(
        string $path,
        string $datasetKey,
        array $packageRecord,
        array $records,
        ?callable $progress,
    ): void {
        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL);
        if ($opened !== true) {
            throw new RuntimeException("Unable to create the spell history ZIP (ZipArchive error {$opened}).");
        }

        try {
            if (! $zip->addFromString($packageRecord['entry'], $packageRecord['contents'])) {
                throw new RuntimeException('Unable to add package.json to the spell history ZIP.');
            }
            $zip->setCompressionName($packageRecord['entry'], ZipArchive::CM_DEFLATE, 6);

            $total = count($records);
            foreach ($records as $index => $record) {
                $entry = "dataset/{$datasetKey}/{$record['relative']}";
                if (! $zip->addFile($record['path'], $entry)) {
                    throw new RuntimeException("Unable to add {$record['relative']} to the spell history ZIP.");
                }
                $zip->setCompressionName($entry, ZipArchive::CM_DEFLATE, 6);
                $current = $index + 1;
                if ($progress !== null && ($current === 1 || $current === $total || $current % 5_000 === 0)) {
                    $progress('archive', ['current' => $current, 'total' => $total]);
                }
            }

            if (! $zip->close()) {
                throw new RuntimeException('Unable to finalize the spell history ZIP.');
            }
        } catch (Throwable $exception) {
            $zip->unchangeAll();
            $zip->close();
            throw $exception;
        }
    }

    /**
     * Re-read every archived entry so the published digest covers the exact
     * bytes that were validated, even if a source file changed during close().
     *
     * @param  array{entry: string, path: null, contents: string, bytes: int, sha256: string}  $packageRecord
     * @param  list<array{relative: string, path: string, bytes: int, sha256: string}>  $records
     */
    private function verifyArchive(
        string $path,
        string $datasetKey,
        array $packageRecord,
        array $records,
    ): void {
        $expected = [
            $packageRecord['entry'] => $packageRecord,
        ];
        foreach ($records as $record) {
            $expected["dataset/{$datasetKey}/{$record['relative']}"] = $record;
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new RuntimeException("Unable to verify the spell history ZIP (ZipArchive error {$opened}).");
        }

        try {
            if ($zip->numFiles !== count($expected)) {
                throw new RuntimeException('The completed spell history ZIP has an unexpected entry count.');
            }
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index, ZipArchive::FL_UNCHANGED);
                if (! is_string($name) || ! isset($expected[$name]) || isset($seen[$name])) {
                    throw new RuntimeException('The completed spell history ZIP contains an unexpected or duplicate entry.');
                }
                $seen[$name] = true;
            }

            foreach ($expected as $entry => $record) {
                $stat = $zip->statName($entry, ZipArchive::FL_UNCHANGED);
                if (! is_array($stat) || ($stat['size'] ?? null) !== $record['bytes']) {
                    throw new RuntimeException("The completed spell history ZIP has an invalid entry size: {$entry}");
                }
                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    throw new RuntimeException("Unable to read the completed spell history ZIP entry: {$entry}");
                }
                $hash = hash_init('sha256');
                $bytes = 0;
                try {
                    while (! feof($stream)) {
                        $chunk = fread($stream, 1_048_576);
                        if ($chunk === false) {
                            throw new RuntimeException("Unable to verify the completed spell history ZIP entry: {$entry}");
                        }
                        if ($chunk === '') {
                            continue;
                        }
                        $bytes += strlen($chunk);
                        hash_update($hash, $chunk);
                    }
                } finally {
                    fclose($stream);
                }
                if ($bytes !== $record['bytes'] || ! hash_equals($record['sha256'], hash_final($hash))) {
                    throw new RuntimeException("The completed spell history ZIP entry changed while packaging: {$entry}");
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array{name: string, sha256: ?string, bytes: ?int}  $archive
     * @return array<string, mixed>
     */
    private function descriptor(array $validated, array $archive): array
    {
        return [
            'schema' => self::PACKAGE_SCHEMA,
            'package_version' => self::PACKAGE_VERSION,
            'dataset' => $validated['dataset'],
            'artifact_format_version' => $validated['format_version'],
            'canonical_format_version' => $validated['canonical_format_version'],
            'first_capture' => $validated['first_capture'],
            'latest_capture' => $validated['latest_capture'],
            'stats' => $validated['stats'],
            'archive' => $archive,
        ];
    }

    private function archiveName(
        ?string $requested,
        int $formatVersion,
        int $canonicalFormatVersion,
        string $latestCapture,
        string $datasetKey,
    ): string {
        $name = is_string($requested) && trim($requested) !== ''
            ? trim($requested)
            : sprintf(
                'modern-allaclone-spell-history-f%d-c%d-%s-%s.zip',
                $formatVersion,
                $canonicalFormatVersion,
                substr($latestCapture, 0, 10),
                substr($datasetKey, 0, 12),
            );
        if (! str_ends_with(strtolower($name), '.zip')) {
            $name .= '.zip';
        }
        if (strlen($name) > 200
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/D', $name) !== 1
            || basename($name) !== $name) {
            throw new RuntimeException('Spell history archive name may contain only letters, numbers, dots, dashes, and underscores.');
        }

        return $name;
    }

    /** @param array<string, mixed> $value */
    private function encodeJson(array $value, string $label): string
    {
        try {
            $json = json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException("Unable to encode {$label}.", 0, $exception);
        }

        return $json."\n";
    }

    private function readCurrentPointer(string $artifactRoot): string
    {
        $path = $artifactRoot.'/CURRENT';
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('Spell history CURRENT must be a regular file.');
        }
        SafePath::assertContained($path, $artifactRoot, 'Spell history CURRENT');
        clearstatcache(true, $path);
        $bytes = filesize($path);
        $pointer = $bytes !== false && $bytes >= 64 && $bytes <= 66 ? file_get_contents($path) : false;
        if (! is_string($pointer) || preg_match('/^([a-f0-9]{64})\r?\n?$/D', $pointer, $matches) !== 1) {
            throw new RuntimeException('Spell history CURRENT contains an invalid dataset key.');
        }

        return $matches[1];
    }

    private function writeNewFile(string $path, string $contents, string $label): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Unable to create {$label}.");
        }
        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException("Unable to write {$label}.");
                }
                $offset += $written;
            }
            if (! fflush($handle)) {
                throw new RuntimeException("Unable to flush {$label}.");
            }
        } finally {
            fclose($handle);
        }
    }

    private function publishNoClobber(string $temporaryPath, string $releasePath): void
    {
        if (file_exists($releasePath) || is_link($releasePath)) {
            throw new RuntimeException("Refusing to overwrite an existing release asset: {$releasePath}");
        }
        if (is_link($temporaryPath) || ! is_file($temporaryPath) || ! @link($temporaryPath, $releasePath)) {
            if (file_exists($releasePath) || is_link($releasePath)) {
                throw new RuntimeException("Refusing to overwrite an existing release asset: {$releasePath}");
            }
            throw new RuntimeException("Unable to atomically publish spell history release asset: {$releasePath}");
        }
        if (! unlink($temporaryPath)) {
            @unlink($releasePath);
            throw new RuntimeException("Unable to finalize spell history release asset: {$releasePath}");
        }
    }

    private function withOutputLock(string $outputRoot, callable $operation): mixed
    {
        $lockPath = $outputRoot.'/.package.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new RuntimeException('Spell history package lock must be a regular file.');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock spell history package publication.');
        }

        try {
            SafePath::assertContained($lockPath, $outputRoot, 'Spell history package lock');

            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

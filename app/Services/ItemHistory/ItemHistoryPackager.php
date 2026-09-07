<?php

namespace App\Services\ItemHistory;

use App\Services\SpellHistory\SafePath;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class ItemHistoryPackager
{
    private const DATASET_HASH_DOMAIN = "modern-allaclone.item-history-dataset-v1\0";

    private const MAX_PACKAGE_FILES = 200_000;

    private const MAX_DATASET_BYTES = 3_221_225_472;

    private const MAX_RELEASE_ASSET_BYTES = 1_073_741_824;

    private const MAX_CRAWLER_METADATA_BYTES = 4_194_304;

    private const MAX_CRAWLER_QUEUE_BYTES = 67_108_864;

    private const BASE_MEMORY_BUDGET_BYTES = 268_435_456;

    private const MEMORY_BUDGET_PER_ITEM_BYTES = 2_368;

    private readonly ItemHistoryPermissions $permissions;

    public function __construct(
        private readonly ItemHistoryMutationLock $mutationLock,
        ?ItemHistoryPermissions $permissions = null,
    ) {
        $this->permissions = $permissions ?? new ItemHistoryPermissions;
    }

    /**
     * @param  (callable(string, array<string, int|string>): void)|null  $progress
     * @param  list<int>|null  $expectedItemIds
     * @return array{
     *     dataset: string,
     *     archive_name: string,
     *     zip_path: string,
     *     checksum_path: string,
     *     descriptor_path: string,
     *     sha256: string,
     *     archive_bytes: int,
     *     stats: array{item_count: int, revision_count: int, artifact_bytes: int, file_count: int, unpacked_bytes: int},
     *     warnings: list<string>
     * }
     */
    public function create(
        string $artifactDirectory,
        string $outputDirectory,
        ?string $archiveName = null,
        ?callable $progress = null,
        ?string $crawlerWorkspace = null,
        ?string $crawlerArtifactRootIdentity = null,
        ?string $crawlerWorkspaceIdentity = null,
    ): array {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to package item history releases.');
        }
        if (is_link($artifactDirectory)) {
            throw new RuntimeException('Item history artifact root cannot be a symbolic link.');
        }
        $artifactRoot = SafePath::existingDirectory($artifactDirectory, 'Item history artifact root');

        if (is_link($outputDirectory)) {
            throw new RuntimeException('Item history package output cannot be a symbolic link.');
        }
        $prospectiveOutput = SafePath::prospectiveDirectory($outputDirectory, 'Item history package output');
        $this->assertOutputOutsideArtifactData($artifactRoot, $outputDirectory, $prospectiveOutput);
        $outputRoot = SafePath::createDirectory($prospectiveOutput, 'Item history package output');

        return $this->withOutputLock(
            $outputRoot,
            fn (): array => $this->mutationLock->shared(
                $artifactRoot,
                fn (): array => $this->withActivationLock(
                    $artifactRoot,
                    fn (): array => $this->createLocked(
                        $artifactRoot,
                        $outputRoot,
                        $archiveName,
                        $progress,
                        $crawlerWorkspace,
                        $crawlerArtifactRootIdentity,
                        $crawlerWorkspaceIdentity,
                    ),
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
        ?string $crawlerWorkspace,
        ?string $crawlerArtifactRootIdentity,
        ?string $crawlerWorkspaceIdentity,
    ): array {
        $source = $this->resolveSource($artifactRoot);
        $this->assertMemoryBudget($source['items_root']);

        if ($source['dataset'] !== null) {
            return $this->createFromSource(
                $source,
                $outputRoot,
                $requestedArchiveName,
                $progress,
                null,
            );
        }

        $workspace = $crawlerWorkspace ?? dirname($artifactRoot).'/lucy-item-history-crawl';

        return $this->withCrawlerWorkspaceLock(
            $workspace,
            $artifactRoot,
            function (string $workspaceRoot, array $crawlerLock) use (
                $source,
                $outputRoot,
                $requestedArchiveName,
                $progress,
                $crawlerArtifactRootIdentity,
                $crawlerWorkspaceIdentity,
            ): array {
                $proof = $this->readCompletedCrawlerProof(
                    $workspaceRoot,
                    $source['artifact_root'],
                    $crawlerLock,
                    $crawlerArtifactRootIdentity,
                    $crawlerWorkspaceIdentity,
                );

                return $this->createFromSource(
                    $source,
                    $outputRoot,
                    $requestedArchiveName,
                    $progress,
                    $proof,
                );
            },
        );
    }

    /**
     * @param  array{artifact_root: string, dataset_root: string, items_root: string, dataset: ?string, pointer_filename: ?string}  $source
     * @param  array<string, mixed>|null  $crawlerProof
     * @param  (callable(string, array<string, int|string>): void)|null  $progress
     * @return array<string, mixed>
     */
    private function createFromSource(
        array $source,
        string $outputRoot,
        ?string $requestedArchiveName,
        ?callable $progress,
        ?array $crawlerProof,
    ): array {
        if (SafePath::pathsOverlap($source['items_root'], $outputRoot)) {
            throw new RuntimeException('Item history package output cannot overlap the source items directory.');
        }

        $validated = $this->validateItems(
            $source['artifact_root'],
            $source['dataset_root'],
            $source['items_root'],
            $progress,
            $crawlerProof['item_ids'] ?? null,
        );
        $datasetKey = $this->datasetKey(
            $validated['artifact_format_versions'],
            $validated['parser_format_versions'],
            $validated['records'],
        );
        if ($source['dataset'] !== null && ! hash_equals($source['dataset'], $datasetKey)) {
            throw new RuntimeException('The active item history dataset key does not match its item artifacts.');
        }

        $manifest = [
            'schema' => ItemHistoryDataset::DATASET_SCHEMA,
            'artifact_type' => 'manifest',
            'format_version' => ItemHistoryDataset::DATASET_FORMAT_VERSION,
            'dataset' => $datasetKey,
            'artifact_schema' => ItemHistoryArtifact::SCHEMA,
            'artifact_format_versions' => $validated['artifact_format_versions'],
            'parser_format_versions' => $validated['parser_format_versions'],
            'first_observed_at' => $validated['first_observed_at'],
            'last_observed_at' => $validated['last_observed_at'],
            'latest_generated_at' => $validated['latest_generated_at'],
            'stats' => [
                'item_count' => $validated['item_count'],
                'revision_count' => $validated['revision_count'],
                'artifact_bytes' => $validated['artifact_bytes'],
                'file_count' => $validated['item_count'] + 2,
            ],
        ];
        $manifestJson = $this->encodeManifest($manifest, $validated['records']);
        $completion = [
            'schema' => ItemHistoryDataset::DATASET_SCHEMA,
            'artifact_type' => 'completion',
            'format_version' => ItemHistoryDataset::DATASET_FORMAT_VERSION,
            'dataset' => $datasetKey,
            'manifest_sha256' => hash('sha256', $manifestJson),
            'manifest_bytes' => strlen($manifestJson),
            'item_count' => $validated['item_count'],
            'revision_count' => $validated['revision_count'],
            'artifact_bytes' => $validated['artifact_bytes'],
            'file_count' => $validated['item_count'] + 2,
        ];
        $completionJson = $this->encodeJson($completion, 'item history completion marker');

        if ($source['dataset'] !== null) {
            $this->assertExistingMetadata($source['dataset_root'], $manifestJson, $completionJson);
        }

        $stats = [
            ...$manifest['stats'],
            'unpacked_bytes' => $validated['artifact_bytes'] + strlen($manifestJson) + strlen($completionJson),
        ];
        if ($stats['unpacked_bytes'] > self::MAX_DATASET_BYTES) {
            throw new RuntimeException('The completed item history dataset exceeds the packaging byte limit.');
        }

        $archiveName = $this->archiveName(
            $requestedArchiveName,
            $validated['artifact_format_versions'],
            $validated['parser_format_versions'],
            $validated['latest_generated_at'],
            $datasetKey,
        );
        $zipPath = $outputRoot.'/'.$archiveName;
        $checksumPath = $zipPath.'.sha256';
        $descriptorPath = $outputRoot.'/'.ItemHistoryDataset::RELEASE_DESCRIPTOR;
        foreach ([$zipPath, $checksumPath, $descriptorPath] as $releasePath) {
            if (file_exists($releasePath) || is_link($releasePath)) {
                throw new RuntimeException("Refusing to overwrite an existing release asset: {$releasePath}");
            }
        }

        $descriptorBase = [
            'schema' => ItemHistoryDataset::PACKAGE_SCHEMA,
            'package_version' => ItemHistoryDataset::PACKAGE_VERSION,
            'dataset' => $datasetKey,
            'dataset_format_version' => ItemHistoryDataset::DATASET_FORMAT_VERSION,
            'artifact_schema' => ItemHistoryArtifact::SCHEMA,
            'artifact_format_versions' => $validated['artifact_format_versions'],
            'parser_format_versions' => $validated['parser_format_versions'],
            'first_observed_at' => $validated['first_observed_at'],
            'last_observed_at' => $validated['last_observed_at'],
            'latest_generated_at' => $validated['latest_generated_at'],
            'stats' => $stats,
        ];
        $insideDescriptor = [
            ...$descriptorBase,
            'archive' => [
                'name' => $archiveName,
                'sha256' => null,
                'bytes' => null,
            ],
        ];
        $packageJson = $this->encodeJson($insideDescriptor, 'item history package metadata');
        if (strlen($packageJson) > self::MAX_DATASET_BYTES - $stats['unpacked_bytes']) {
            throw new RuntimeException('The completed item history package exceeds the unpacked-byte limit.');
        }
        $metadataRecords = [
            $this->memoryRecord('package.json', $packageJson),
            $this->memoryRecord("dataset/{$datasetKey}/manifest.json", $manifestJson),
            $this->memoryRecord("dataset/{$datasetKey}/COMPLETE.json", $completionJson),
        ];
        $itemRecords = $validated['records'];
        unset(
            $validated['records'],
            $manifest,
            $completion,
            $insideDescriptor,
            $packageJson,
            $manifestJson,
            $completionJson,
        );

        $temporaryZip = $outputRoot.'/.item-history-'.bin2hex(random_bytes(12)).'.zip.tmp';
        $temporaryChecksum = $outputRoot.'/.item-history-'.bin2hex(random_bytes(12)).'.sha256.tmp';
        $temporaryDescriptor = $outputRoot.'/.item-history-'.bin2hex(random_bytes(12)).'.json.tmp';
        $published = [];

        try {
            $this->writeArchive(
                $temporaryZip,
                $datasetKey,
                $metadataRecords,
                $itemRecords,
                $progress,
            );
            $this->verifyArchive(
                $temporaryZip,
                $datasetKey,
                $metadataRecords,
                $itemRecords,
            );
            $this->assertSourceStable($source, $itemRecords, $crawlerProof);
            unset($metadataRecords);

            clearstatcache(true, $temporaryZip);
            $archiveBytes = filesize($temporaryZip);
            $archiveHash = hash_file('sha256', $temporaryZip);
            if ($archiveBytes === false || $archiveBytes < 1 || $archiveBytes > self::MAX_RELEASE_ASSET_BYTES) {
                throw new RuntimeException('The item history ZIP is empty or exceeds the supported GitHub release asset size.');
            }
            if (! is_string($archiveHash) || preg_match(ItemHistoryDataset::DATASET_KEY_PATTERN, $archiveHash) !== 1) {
                throw new RuntimeException('Unable to hash the completed item history ZIP.');
            }

            $outsideDescriptor = [
                ...$descriptorBase,
                'archive' => [
                    'name' => $archiveName,
                    'sha256' => $archiveHash,
                    'bytes' => $archiveBytes,
                ],
            ];
            $this->writeNewFile(
                $temporaryChecksum,
                "{$archiveHash}  {$archiveName}\n",
                'item history checksum sidecar',
            );
            $this->writeNewFile(
                $temporaryDescriptor,
                $this->encodeJson($outsideDescriptor, 'item history release descriptor'),
                'item history release descriptor',
            );
            $this->assertSourceStable($source, $itemRecords, $crawlerProof);
            unset($itemRecords);

            foreach ([
                [$temporaryZip, $zipPath],
                [$temporaryChecksum, $checksumPath],
                [$temporaryDescriptor, $descriptorPath],
            ] as [$temporaryPath, $releasePath]) {
                $this->publishNoClobber($temporaryPath, $releasePath);
                $published[] = $releasePath;
            }
            if ($progress !== null) {
                $progress('committed', [
                    'current' => $stats['item_count'],
                    'total' => $stats['item_count'],
                ]);
            }

            return [
                'dataset' => $datasetKey,
                'archive_name' => $archiveName,
                'zip_path' => SafePath::normalize($zipPath),
                'checksum_path' => SafePath::normalize($checksumPath),
                'descriptor_path' => SafePath::normalize($descriptorPath),
                'sha256' => $archiveHash,
                'archive_bytes' => $archiveBytes,
                'stats' => $stats,
                'warnings' => [],
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
     * @return array{artifact_root: string, dataset_root: string, items_root: string, dataset: ?string, pointer_filename: ?string}
     */
    private function resolveSource(string $artifactRoot): array
    {
        $pointer = $this->activePointer($artifactRoot);
        if ($pointer !== null) {
            $dataset = $pointer['dataset'];

            $datasetsPath = $artifactRoot.'/datasets';
            if (is_link($datasetsPath)) {
                throw new RuntimeException('Item history datasets directory cannot be a symbolic link.');
            }
            $datasetsRoot = SafePath::existingDirectory($datasetsPath, 'Item history datasets directory');
            SafePath::assertContained($datasetsRoot, $artifactRoot, 'Item history datasets directory');
            $datasetPath = $datasetsRoot.'/'.$dataset;
            if (is_link($datasetPath)) {
                throw new RuntimeException('The active item history dataset cannot be a symbolic link.');
            }
            $datasetRoot = SafePath::existingDirectory($datasetPath, 'Active item history dataset');
            SafePath::assertContained($datasetRoot, $datasetsRoot, 'Active item history dataset');
            $this->assertExactEntries($datasetRoot, ['COMPLETE.json', 'items', 'manifest.json'], 'active dataset');
        } else {
            $dataset = null;
            $datasetRoot = $artifactRoot;
        }

        $itemsPath = $datasetRoot.'/items';
        if (is_link($itemsPath)) {
            throw new RuntimeException('Item history items directory cannot be a symbolic link.');
        }
        $itemsRoot = SafePath::existingDirectory($itemsPath, 'Item history items directory');
        SafePath::assertContained($itemsRoot, $datasetRoot, 'Item history items directory');

        return [
            'artifact_root' => $artifactRoot,
            'dataset_root' => $datasetRoot,
            'items_root' => $itemsRoot,
            'dataset' => $dataset,
            'pointer_filename' => $pointer['filename'] ?? null,
        ];
    }

    /**
     * @param  (callable(string, array<string, int|string>): void)|null  $progress
     * @return array{
     *     item_count: int,
     *     revision_count: int,
     *     artifact_bytes: int,
     *     artifact_format_versions: list<int>,
     *     parser_format_versions: list<int>,
     *     first_observed_at: string,
     *     last_observed_at: string,
     *     latest_generated_at: string,
     *     records: list<array{item_id: int, relative: string, path: string, bytes: int, sha256: string}>
     * }
     */
    private function validateItems(
        string $repositoryRoot,
        string $datasetRoot,
        string $itemsRoot,
        ?callable $progress,
        ?array $expectedItemIds,
    ): array {
        // Point the repository at the configured artifact root. Constructing it
        // on an immutable dataset directory would create an activation lock
        // inside that dataset; the outer shared lock keeps this selection fixed.
        $repository = new ItemHistoryRepository($repositoryRoot);
        $records = [];
        $revisionCount = 0;
        $artifactBytes = 0;
        $artifactVersions = [];
        $parserVersions = [];
        $firstObservedAt = null;
        $lastObservedAt = null;
        $latestGeneratedAt = null;
        $latestGeneratedInstant = null;

        foreach ($this->directoryEntries($itemsRoot, 'item history items directory') as $shard) {
            if (preg_match('/^[a-f0-9]{2}$/D', $shard) !== 1) {
                throw new RuntimeException("Unexpected entry in item shard directory: {$shard}");
            }
            $shardPath = $itemsRoot.'/'.$shard;
            if (is_link($shardPath) || ! is_dir($shardPath)) {
                throw new RuntimeException("Item history shard {$shard} must be a regular directory.");
            }
            $resolvedShard = SafePath::assertContained($shardPath, $itemsRoot, "Item history shard {$shard}");
            $itemFiles = $this->directoryEntries($resolvedShard, "item history shard {$shard}");
            if ($itemFiles === []) {
                throw new RuntimeException("Item history shard {$shard} cannot be empty.");
            }

            foreach ($itemFiles as $filename) {
                if (preg_match('/^([1-9][0-9]*)\.json$/D', $filename, $matches) !== 1) {
                    throw new RuntimeException("Unexpected entry in item history shard {$shard}: {$filename}");
                }
                $itemId = filter_var($matches[1], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
                ]);
                $relative = "items/{$shard}/{$filename}";
                if ($itemId === false || ItemHistoryArtifact::itemRelativePath($itemId) !== $relative) {
                    throw new RuntimeException("Item history artifact has an invalid shard path: {$relative}");
                }

                $itemRecord = $this->readItemRecord(
                    $resolvedShard.'/'.$filename,
                    $relative,
                    $itemId,
                    $datasetRoot,
                    $repository,
                );
                $artifact = $itemRecord['decoded'];
                unset($itemRecord['decoded']);
                $records[] = $itemRecord;
                $count = count($records);
                if ($count > self::MAX_PACKAGE_FILES - 2) {
                    throw new RuntimeException('The item history dataset exceeds the packaging file-count limit.');
                }

                $artifactBytes += $itemRecord['bytes'];
                if ($artifactBytes > self::MAX_DATASET_BYTES) {
                    throw new RuntimeException('The item history dataset exceeds the packaging byte limit.');
                }
                $revisionCount += $artifact['revision_count'];
                $artifactVersions[$artifact['format_version']] = true;
                if (array_key_exists('parser_format_version', $artifact)) {
                    $parserVersions[$artifact['parser_format_version']] = true;
                }
                $firstObservedAt = $firstObservedAt === null || $artifact['first_observed_at'] < $firstObservedAt
                    ? $artifact['first_observed_at']
                    : $firstObservedAt;
                $lastObservedAt = $lastObservedAt === null || $artifact['last_observed_at'] > $lastObservedAt
                    ? $artifact['last_observed_at']
                    : $lastObservedAt;
                $generatedInstant = $this->generatedInstant($artifact['generated_at']);
                if ($latestGeneratedInstant === null || $generatedInstant > $latestGeneratedInstant) {
                    $latestGeneratedInstant = $generatedInstant;
                    $latestGeneratedAt = $artifact['generated_at'];
                }

                if ($progress !== null && ($count === 1 || $count % 5_000 === 0)) {
                    $progress('validate', ['current' => $count, 'total' => 0]);
                }
            }
        }

        if ($records === [] || $firstObservedAt === null || $lastObservedAt === null || $latestGeneratedAt === null) {
            throw new RuntimeException('The item history dataset contains no complete item artifacts.');
        }
        if ($expectedItemIds !== null) {
            if (count($records) !== count($expectedItemIds)) {
                throw new RuntimeException(
                    'The flat item-history artifact count does not match the completed crawler queue.',
                );
            }
            usort($records, static fn (array $left, array $right): int => $left['item_id'] <=> $right['item_id']);
            foreach ($expectedItemIds as $index => $expectedItemId) {
                if ($records[$index]['item_id'] !== $expectedItemId) {
                    throw new RuntimeException(
                        'The flat item-history artifact IDs do not exactly match the completed crawler queue.',
                    );
                }
            }
        }
        usort($records, static fn (array $left, array $right): int => strcmp($left['relative'], $right['relative']));
        $artifactFormatVersions = array_map('intval', array_keys($artifactVersions));
        $parserFormatVersions = array_map('intval', array_keys($parserVersions));
        sort($artifactFormatVersions, SORT_NUMERIC);
        sort($parserFormatVersions, SORT_NUMERIC);
        if ($progress !== null) {
            $progress('validate', ['current' => count($records), 'total' => count($records)]);
        }

        return [
            'item_count' => count($records),
            'revision_count' => $revisionCount,
            'artifact_bytes' => $artifactBytes,
            'artifact_format_versions' => $artifactFormatVersions,
            'parser_format_versions' => $parserFormatVersions,
            'first_observed_at' => $firstObservedAt,
            'last_observed_at' => $lastObservedAt,
            'latest_generated_at' => $latestGeneratedAt,
            'records' => $records,
        ];
    }

    /**
     * @return array{item_id: int, relative: string, path: string, bytes: int, sha256: string, decoded: array<string, mixed>}
     */
    private function readItemRecord(
        string $path,
        string $relative,
        int $itemId,
        string $datasetRoot,
        ItemHistoryRepository $repository,
    ): array {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException("Missing or unsafe item history artifact: {$relative}");
        }
        $resolved = SafePath::assertContained($path, $datasetRoot, "Item history artifact {$itemId}");
        clearstatcache(true, $resolved);
        $bytes = filesize($resolved);
        if ($bytes === false || $bytes < 2 || $bytes > ItemHistoryArtifact::MAX_ITEM_BYTES) {
            throw new RuntimeException("Item history artifact {$itemId} has an invalid size.");
        }
        $json = file_get_contents($resolved);
        if ($json === false || strlen($json) !== $bytes) {
            throw new RuntimeException("Unable to read item history artifact {$itemId}.");
        }
        try {
            $artifact = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Item history artifact {$itemId} is not valid JSON.", 0, $exception);
        }
        if (! is_array($artifact) || array_is_list($artifact)) {
            throw new RuntimeException("Item history artifact {$itemId} must be a JSON object.");
        }
        $this->assertItemEnvelope($artifact, $itemId);
        $sha256 = hash('sha256', $json);
        unset($json);

        // The repository owns the complete semantic contract. Hashing before
        // and after this validation proves it inspected the bytes we recorded.
        if ($repository->item($itemId) === null) {
            throw new RuntimeException("Item history artifact {$itemId} disappeared during validation.");
        }
        clearstatcache(true, $resolved);
        $afterBytes = filesize($resolved);
        $afterHash = hash_file('sha256', $resolved);
        if ($afterBytes !== $bytes || ! is_string($afterHash) || ! hash_equals($sha256, $afterHash)) {
            throw new RuntimeException("Item history artifact {$itemId} changed during validation.");
        }

        return [
            'item_id' => $itemId,
            'relative' => $relative,
            'path' => $resolved,
            'bytes' => $bytes,
            'sha256' => $sha256,
            'decoded' => $artifact,
        ];
    }

    /** @param array<string, mixed> $artifact */
    private function assertItemEnvelope(array $artifact, int $itemId): void
    {
        $format = $artifact['format_version'] ?? null;
        $parser = $artifact['parser_format_version'] ?? null;
        $validVersionPair = ($format === ItemHistoryArtifact::FORMAT_VERSION
                && (! array_key_exists('parser_format_version', $artifact)
                    || $parser === ItemHistoryArtifact::DIRECT_DETAIL_PARSER_FORMAT_VERSION))
            || ($format === ItemHistoryArtifact::REVERSIBLE_DELTA_FORMAT_VERSION
                && $parser === ItemHistoryArtifact::REVERSIBLE_DELTA_PARSER_FORMAT_VERSION);
        $revisions = $artifact['revisions'] ?? null;
        if (($artifact['schema'] ?? null) !== ItemHistoryArtifact::SCHEMA
            || ($artifact['artifact_type'] ?? null) !== 'item'
            || ! $validVersionPair
            || ($artifact['item_id'] ?? null) !== $itemId
            || ($artifact['complete'] ?? null) !== true
            || ($artifact['gaps'] ?? null) !== []
            || ! is_array($revisions) || ! array_is_list($revisions) || $revisions === []
            || ! is_int($artifact['revision_count'] ?? null)
            || $artifact['revision_count'] !== count($revisions)
            || ! is_string($artifact['first_observed_at'] ?? null)
            || ! is_string($artifact['last_observed_at'] ?? null)
            || ! is_string($artifact['generated_at'] ?? null)
            || $this->generatedInstant($artifact['generated_at']) === null) {
            throw new RuntimeException("Item history artifact {$itemId} has an incompatible or incomplete envelope.");
        }
    }

    /**
     * @param  list<int>  $artifactVersions
     * @param  list<int>  $parserVersions
     * @param  list<array{relative: string, path: string, bytes: int, sha256: string}>  $records
     */
    private function datasetKey(array $artifactVersions, array $parserVersions, array $records): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, self::DATASET_HASH_DOMAIN);
        hash_update($hash, ItemHistoryArtifact::SCHEMA."\0");
        hash_update($hash, implode(',', $artifactVersions)."\0");
        hash_update($hash, implode(',', $parserVersions)."\0");
        foreach ($records as $record) {
            hash_update(
                $hash,
                $record['relative']."\0".$record['bytes']."\0".$record['sha256']."\n",
            );
        }

        return hash_final($hash);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array{relative: string, path: string, bytes: int, sha256: string}>  $records
     */
    private function encodeManifest(array $manifest, array $records): string
    {
        $header = $this->encodeJsonValue($manifest, 'item history manifest');
        // Spill the construction buffer after 1 MiB. The finished manifest
        // must still be materialized for ZipArchive::addFromString(), but this
        // avoids retaining a second 20-30 MiB in-memory copy while building it.
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to create the item history manifest buffer.');
        }

        try {
            $this->writeAll($stream, substr($header, 0, -1).',"files":[', 'item history manifest');
            foreach ($records as $index => $record) {
                if ($index > 0) {
                    $this->writeAll($stream, ',', 'item history manifest');
                }
                $this->writeAll($stream, $this->encodeJsonValue([
                    'path' => $record['relative'],
                    'bytes' => $record['bytes'],
                    'sha256' => $record['sha256'],
                ], 'item history manifest file'), 'item history manifest');

                $position = ftell($stream);
                if (! is_int($position) || $position > ItemHistoryDataset::MAX_MANIFEST_BYTES - 3) {
                    throw new RuntimeException('The item history manifest exceeds its safe size limit.');
                }
            }
            $this->writeAll($stream, "]}\n", 'item history manifest');
            $length = ftell($stream);
            if (! is_int($length) || $length < 2 || $length > ItemHistoryDataset::MAX_MANIFEST_BYTES) {
                throw new RuntimeException('The item history manifest has an invalid size.');
            }
            rewind($stream);
            $json = stream_get_contents($stream);
            if (! is_string($json) || strlen($json) !== $length) {
                throw new RuntimeException('Unable to read the completed item history manifest.');
            }

            return $json;
        } finally {
            fclose($stream);
        }
    }

    private function assertExistingMetadata(string $datasetRoot, string $manifest, string $completion): void
    {
        foreach ([
            ['manifest.json', $manifest, ItemHistoryDataset::MAX_MANIFEST_BYTES],
            ['COMPLETE.json', $completion, ItemHistoryDataset::MAX_COMPLETION_BYTES],
        ] as [$filename, $expected, $maximum]) {
            $path = $datasetRoot.'/'.$filename;
            if (is_link($path) || ! is_file($path)) {
                throw new RuntimeException("The active item history dataset has no safe {$filename}.");
            }
            SafePath::assertContained($path, $datasetRoot, "Item history {$filename}");
            clearstatcache(true, $path);
            $bytes = filesize($path);
            if ($bytes === false || $bytes < 2 || $bytes > $maximum || $bytes !== strlen($expected)) {
                throw new RuntimeException("The active item history {$filename} does not match its item artifacts.");
            }
            $actualHash = hash_file('sha256', $path);
            if (! is_string($actualHash) || ! hash_equals(hash('sha256', $expected), $actualHash)) {
                throw new RuntimeException("The active item history {$filename} does not match its item artifacts.");
            }
        }
    }

    /**
     * @param  array{path: string, owner_path: string, token: string, owner_bytes: int, owner_sha256: string}  $crawlerLock
     * @return array{
     *     item_ids: list<int>,
     *     files: list<array{label: string, primary_path: string, path: string, backup: bool, bytes: int, sha256: string}>,
     *     lock: array{path: string, owner_path: string, token: string, owner_bytes: int, owner_sha256: string}
     * }
     */
    private function readCompletedCrawlerProof(
        string $workspaceRoot,
        string $artifactRoot,
        array $crawlerLock,
        ?string $crawlerArtifactRootIdentity,
        ?string $crawlerWorkspaceIdentity,
    ): array {
        $configRecord = $this->readCrawlerJson(
            $workspaceRoot.'/config.json',
            self::MAX_CRAWLER_METADATA_BYTES,
            'crawler configuration',
            $workspaceRoot,
        );
        $config = $configRecord['decoded'];
        unset($configRecord['decoded']);
        if (($config['schema'] ?? null) !== 'modern-allaclone.lucy-item-crawl-config'
            || ($config['version'] ?? null) !== 1
            || ! is_string($config['workspace'] ?? null)
            || ! is_string($config['artifact_root'] ?? null)) {
            throw new RuntimeException(
                'The crawler configuration is incompatible or does not bind this workspace to the flat artifact root.',
            );
        }
        $artifactIdentity = $crawlerArtifactRootIdentity ?? $artifactRoot;
        $workspaceIdentity = $crawlerWorkspaceIdentity ?? $workspaceRoot;
        if (! $this->portablePathsEqual($config['artifact_root'], $artifactIdentity)
            || ! $this->portablePathsEqual($config['workspace'], $workspaceIdentity)) {
            throw new RuntimeException(
                'The crawler configuration does not bind the selected workspace and flat artifact-root identity.',
            );
        }

        $queueRecord = $this->readCrawlerJson(
            $workspaceRoot.'/queue.json',
            self::MAX_CRAWLER_QUEUE_BYTES,
            'crawler item queue',
            $workspaceRoot,
        );
        $queue = $queueRecord['decoded'];
        unset($queueRecord['decoded']);
        $items = $queue['items'] ?? null;
        if (($queue['schema'] ?? null) !== 'modern-allaclone.lucy-item-crawl-queue'
            || ($queue['version'] ?? null) !== 1
            || ! is_array($items) || ! array_is_list($items)
            || ! is_int($queue['item_count'] ?? null)
            || $queue['item_count'] < 1
            || $queue['item_count'] !== count($items)
            || $queue['item_count'] > self::MAX_PACKAGE_FILES - 2) {
            throw new RuntimeException('The crawler item queue is incompatible or exceeds the package limit.');
        }

        $itemIds = [];
        $previousItemId = 0;
        foreach ($items as $item) {
            $itemId = is_array($item) && ! array_is_list($item) ? ($item['id'] ?? null) : null;
            if (! is_int($itemId) || $itemId <= $previousItemId || ! is_string($item['name'] ?? null)) {
                throw new RuntimeException('The crawler item queue contains invalid or unsorted item records.');
            }
            $itemIds[] = $itemId;
            $previousItemId = $itemId;
        }
        unset($items, $queue);

        $progressRecord = $this->readCrawlerJson(
            $workspaceRoot.'/state/progress.json',
            self::MAX_CRAWLER_QUEUE_BYTES,
            'crawler progress',
            $workspaceRoot,
        );
        $progress = $progressRecord['decoded'];
        unset($progressRecord['decoded']);
        $retryIds = $progress['retry_ids'] ?? null;
        if (($progress['schema'] ?? null) !== 'modern-allaclone.lucy-item-crawl-progress'
            || ($progress['version'] ?? null) !== 1
            || ($progress['total_items'] ?? null) !== count($itemIds)
            || ($progress['primary_cursor'] ?? null) !== count($itemIds)
            || ($progress['completed_items'] ?? null) !== count($itemIds)
            || ($progress['failed_items'] ?? null) !== 0
            || ($progress['current_item_id'] ?? null) !== null
            || ($progress['refresh_item_list'] ?? null) !== false
            || ! is_array($retryIds) || ! array_is_list($retryIds)
            || ($progress['retry_cursor'] ?? null) !== count($retryIds)) {
            throw new RuntimeException(
                'The flat item-history source does not have a completed, gap-free crawler progress record.',
            );
        }
        $queueIndex = 0;
        $previousRetryId = 0;
        foreach ($retryIds as $retryId) {
            if (! is_int($retryId) || $retryId <= $previousRetryId) {
                throw new RuntimeException('The completed crawler retry inventory is invalid or unsorted.');
            }
            while (isset($itemIds[$queueIndex]) && $itemIds[$queueIndex] < $retryId) {
                $queueIndex++;
            }
            if (! isset($itemIds[$queueIndex]) || $itemIds[$queueIndex] !== $retryId) {
                throw new RuntimeException('The completed crawler retry inventory is not part of the item queue.');
            }
            $previousRetryId = $retryId;
        }
        unset($progress);

        $statusRecord = $this->readCrawlerJson(
            $workspaceRoot.'/state/status.json',
            self::MAX_CRAWLER_METADATA_BYTES,
            'crawler status',
            $workspaceRoot,
        );
        $status = $statusRecord['decoded'];
        unset($statusRecord['decoded']);
        if (($status['schema'] ?? null) !== 'modern-allaclone.lucy-item-crawl-status'
            || ($status['version'] ?? null) !== 1
            || ($status['state'] ?? null) !== 'complete'
            || ($status['phase'] ?? null) !== 'complete'
            || ! array_key_exists('lastError', $status)
            || $status['lastError'] !== null
            || ! is_string($status['artifactRoot'] ?? null)
            || ! $this->portablePathsEqual($status['artifactRoot'], $config['artifact_root'])) {
            throw new RuntimeException('The crawler status does not certify a completed flat artifact root.');
        }

        return [
            'item_ids' => $itemIds,
            'files' => [$configRecord, $queueRecord, $progressRecord, $statusRecord],
            'lock' => $crawlerLock,
        ];
    }

    /**
     * @return array{label: string, primary_path: string, path: string, backup: bool, bytes: int, sha256: string, decoded: array<string, mixed>}
     */
    private function readCrawlerJson(string $primaryPath, int $maximumBytes, string $label, string $workspaceRoot): array
    {
        if (is_link($primaryPath)) {
            throw new RuntimeException("The {$label} must be a regular file.");
        }
        $backup = false;
        $path = $primaryPath;
        if (! file_exists($path)) {
            $path .= '.bak';
            $backup = true;
        }
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException("The {$label} is missing or unsafe.");
        }
        $resolved = SafePath::assertContained($path, $workspaceRoot, ucfirst($label));
        clearstatcache(true, $resolved);
        $bytes = filesize($resolved);
        if ($bytes === false || $bytes < 2 || $bytes > $maximumBytes) {
            throw new RuntimeException("The {$label} has an invalid size.");
        }
        $json = file_get_contents($resolved);
        if (! is_string($json) || strlen($json) !== $bytes) {
            throw new RuntimeException("Unable to read the {$label}.");
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The {$label} is not valid JSON.", 0, $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("The {$label} must be a JSON object.");
        }

        return [
            'label' => $label,
            'primary_path' => $primaryPath,
            'path' => $resolved,
            'backup' => $backup,
            'bytes' => $bytes,
            'sha256' => hash('sha256', $json),
            'decoded' => $decoded,
        ];
    }

    /**
     * @param  list<array{entry: string, contents: string, bytes: int, sha256: string}>  $metadataRecords
     * @param  list<array{relative: string, path: string, bytes: int, sha256: string}>  $itemRecords
     * @param  (callable(string, array<string, int|string>): void)|null  $progress
     */
    private function writeArchive(
        string $path,
        string $datasetKey,
        array $metadataRecords,
        array $itemRecords,
        ?callable $progress,
    ): void {
        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL);
        if ($opened !== true) {
            throw new RuntimeException("Unable to create the item history ZIP (ZipArchive error {$opened}).");
        }

        try {
            foreach ($metadataRecords as $record) {
                if (! $zip->addFromString($record['entry'], $record['contents'])) {
                    throw new RuntimeException("Unable to add {$record['entry']} to the item history ZIP.");
                }
                $zip->setCompressionName($record['entry'], ZipArchive::CM_DEFLATE, 6);
            }

            $total = count($itemRecords);
            foreach ($itemRecords as $index => $record) {
                $this->assertItemFileUnchanged($record);
                $entry = "dataset/{$datasetKey}/{$record['relative']}";
                if (! $zip->addFile($record['path'], $entry)) {
                    throw new RuntimeException("Unable to add {$record['relative']} to the item history ZIP.");
                }
                $zip->setCompressionName($entry, ZipArchive::CM_DEFLATE, 6);
                $current = $index + 1;
                if ($progress !== null && ($current === 1 || $current === $total || $current % 5_000 === 0)) {
                    $progress('archive', ['current' => $current, 'total' => $total]);
                }
            }

            if (! $zip->close()) {
                foreach ($itemRecords as $record) {
                    $this->assertItemFileUnchanged($record);
                }
                throw new RuntimeException('Unable to finalize the item history ZIP.');
            }
        } catch (Throwable $exception) {
            try {
                $zip->unchangeAll();
            } catch (Throwable) {
                // close() may already have invalidated the handle.
            }
            try {
                $zip->close();
            } catch (Throwable) {
                // Preserve the original packaging failure.
            }
            throw $exception;
        }
    }

    /**
     * @param  list<array{entry: string, contents: string, bytes: int, sha256: string}>  $metadataRecords
     * @param  list<array{relative: string, path: string, bytes: int, sha256: string}>  $itemRecords
     */
    private function verifyArchive(
        string $path,
        string $datasetKey,
        array $metadataRecords,
        array $itemRecords,
    ): void {
        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new RuntimeException("Unable to verify the item history ZIP (ZipArchive error {$opened}).");
        }

        try {
            $expectedCount = count($metadataRecords) + count($itemRecords);
            if ($zip->numFiles !== $expectedCount) {
                throw new RuntimeException('The completed item history ZIP has an unexpected entry count.');
            }

            $index = 0;
            foreach ($metadataRecords as $record) {
                $this->verifyArchiveEntry($zip, $index++, $record['entry'], $record['bytes'], $record['sha256']);
            }
            foreach ($itemRecords as $record) {
                $entry = "dataset/{$datasetKey}/{$record['relative']}";
                $this->verifyArchiveEntry($zip, $index++, $entry, $record['bytes'], $record['sha256']);
                $this->assertItemFileUnchanged($record);
            }
        } finally {
            $zip->close();
        }
    }

    private function verifyArchiveEntry(
        ZipArchive $zip,
        int $index,
        string $expectedName,
        int $expectedBytes,
        string $expectedHash,
    ): void {
        $name = $zip->getNameIndex($index, ZipArchive::FL_UNCHANGED);
        if ($name !== $expectedName) {
            throw new RuntimeException('The completed item history ZIP contains an unexpected or duplicate entry.');
        }
        $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
        if (! is_array($stat) || ($stat['size'] ?? null) !== $expectedBytes) {
            throw new RuntimeException("The completed item history ZIP has an invalid entry size: {$expectedName}");
        }
        $stream = $zip->getStream($expectedName);
        if ($stream === false) {
            throw new RuntimeException("Unable to read the completed item history ZIP entry: {$expectedName}");
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1_048_576);
                if ($chunk === false) {
                    throw new RuntimeException("Unable to verify the completed item history ZIP entry: {$expectedName}");
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
        if ($bytes !== $expectedBytes || ! hash_equals($expectedHash, hash_final($hash))) {
            throw new RuntimeException("The completed item history ZIP entry changed while packaging: {$expectedName}");
        }
    }

    /**
     * @param  array{artifact_root: string, dataset_root: string, items_root: string, dataset: ?string, pointer_filename: ?string}  $source
     * @param  list<array{item_id: int, relative: string, path: string, bytes: int, sha256: string}>  $records
     * @param  array<string, mixed>|null  $crawlerProof
     */
    private function assertSourceStable(array $source, array $records, ?array $crawlerProof): void
    {
        $this->assertItemInventoryMatches($source['items_root'], $records);
        if ($source['dataset'] !== null) {
            $pointer = $this->activePointer($source['artifact_root']);
            if ($pointer === null
                || $pointer['filename'] !== $source['pointer_filename']
                || ! hash_equals($source['dataset'], $pointer['dataset'])) {
                throw new RuntimeException('The active item history dataset changed while packaging.');
            }
        }
        if ($crawlerProof !== null) {
            $this->assertCrawlerProofStable($crawlerProof);
        }
    }

    /**
     * @param array{
     *     files: list<array{label: string, primary_path: string, path: string, backup: bool, bytes: int, sha256: string}>,
     *     lock: array{path: string, owner_path: string, token: string, owner_bytes: int, owner_sha256: string}
     * } $proof
     */
    private function assertCrawlerProofStable(array $proof): void
    {
        foreach ($proof['files'] as $record) {
            if ($record['backup'] && (file_exists($record['primary_path']) || is_link($record['primary_path']))) {
                throw new RuntimeException("The {$record['label']} changed while packaging.");
            }
            $this->assertRecordedFileUnchanged(
                $record['path'],
                $record['bytes'],
                $record['sha256'],
                $record['label'],
            );
        }

        $lock = $proof['lock'];
        if (is_link($lock['path']) || ! is_dir($lock['path'])) {
            throw new RuntimeException('The crawler publication lock was lost while packaging.');
        }
        $this->assertRecordedFileUnchanged(
            $lock['owner_path'],
            $lock['owner_bytes'],
            $lock['owner_sha256'],
            'crawler publication lock owner',
        );
        $ownerJson = file_get_contents($lock['owner_path']);
        if (! is_string($ownerJson)) {
            throw new RuntimeException('Unable to reread the crawler publication lock owner.');
        }
        try {
            $owner = json_decode($ownerJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The crawler publication lock owner changed while packaging.', 0, $exception);
        }
        if (! is_array($owner) || ($owner['token'] ?? null) !== $lock['token']) {
            throw new RuntimeException('The crawler publication lock ownership changed while packaging.');
        }
    }

    private function assertRecordedFileUnchanged(
        string $path,
        int $expectedBytes,
        string $expectedHash,
        string $label,
    ): void {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException("The {$label} changed while packaging.");
        }
        clearstatcache(true, $path);
        $bytes = filesize($path);
        $hash = hash_file('sha256', $path);
        if ($bytes !== $expectedBytes || ! is_string($hash) || ! hash_equals($expectedHash, $hash)) {
            throw new RuntimeException("The {$label} changed while packaging.");
        }
    }

    /**
     * @param  list<array{item_id: int, relative: string, path: string, bytes: int, sha256: string}>  $records
     */
    private function assertItemInventoryMatches(string $itemsRoot, array $records): void
    {
        $index = 0;
        foreach ($this->directoryEntries($itemsRoot, 'item history items directory') as $shard) {
            if (preg_match('/^[a-f0-9]{2}$/D', $shard) !== 1) {
                throw new RuntimeException("Unexpected entry in item shard directory: {$shard}");
            }
            $shardPath = $itemsRoot.'/'.$shard;
            if (is_link($shardPath) || ! is_dir($shardPath)) {
                throw new RuntimeException("Item history shard {$shard} must be a regular directory.");
            }
            $resolvedShard = SafePath::assertContained($shardPath, $itemsRoot, "Item history shard {$shard}");
            foreach ($this->directoryEntries($resolvedShard, "item history shard {$shard}") as $filename) {
                if (preg_match('/^([1-9][0-9]*)\.json$/D', $filename, $matches) !== 1) {
                    throw new RuntimeException("Unexpected entry in item history shard {$shard}: {$filename}");
                }
                $itemId = filter_var($matches[1], FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
                ]);
                $relative = "items/{$shard}/{$filename}";
                if ($itemId === false || ItemHistoryArtifact::itemRelativePath($itemId) !== $relative) {
                    throw new RuntimeException("Item history artifact has an invalid shard path: {$relative}");
                }
                $path = $resolvedShard.'/'.$filename;
                if (is_link($path) || ! is_file($path)) {
                    throw new RuntimeException("Missing or unsafe item history artifact: {$relative}");
                }
                SafePath::assertContained($path, $itemsRoot, "Item history artifact {$itemId}");
                if (! isset($records[$index]) || $records[$index]['relative'] !== $relative) {
                    throw new RuntimeException('The item history artifact set changed while packaging.');
                }
                $index++;
                if ($index > self::MAX_PACKAGE_FILES - 2) {
                    throw new RuntimeException('The item history dataset exceeds the packaging file-count limit.');
                }
            }
        }
        if ($index !== count($records)) {
            throw new RuntimeException('The item history artifact set changed while packaging.');
        }
    }

    /** @param array{item_id: int, relative: string, path: string, bytes: int, sha256: string} $record */
    private function assertItemFileUnchanged(array $record): void
    {
        if (is_link($record['path']) || ! is_file($record['path'])) {
            throw new RuntimeException("Item history artifact changed while packaging: {$record['relative']}");
        }
        clearstatcache(true, $record['path']);
        $bytes = filesize($record['path']);
        $hash = hash_file('sha256', $record['path']);
        if ($bytes !== $record['bytes'] || ! is_string($hash) || ! hash_equals($record['sha256'], $hash)) {
            throw new RuntimeException("Item history artifact changed while packaging: {$record['relative']}");
        }
    }

    /** @return array{entry: string, contents: string, bytes: int, sha256: string} */
    private function memoryRecord(string $entry, string $contents): array
    {
        return [
            'entry' => $entry,
            'contents' => $contents,
            'bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    private function assertMemoryBudget(string $itemsRoot): void
    {
        $limit = $this->memoryLimitBytes();
        if ($limit === null) {
            return;
        }

        $itemCount = 0;
        foreach ($this->directoryEntries($itemsRoot, 'item history items directory') as $shard) {
            $path = $itemsRoot.'/'.$shard;
            if (! is_dir($path) || is_link($path)) {
                continue;
            }
            $itemCount += count($this->directoryEntries($path, "item history shard {$shard}"));
            if ($itemCount > self::MAX_PACKAGE_FILES - 2) {
                break;
            }
        }

        $required = self::BASE_MEMORY_BUDGET_BYTES
            + (2 * ItemHistoryArtifact::MAX_ITEM_BYTES)
            + ($itemCount * self::MEMORY_BUDGET_PER_ITEM_BYTES);
        if ($limit >= $required) {
            return;
        }

        $requiredMiB = (int) ceil($required / 1_048_576);
        $configuredMiB = (int) floor($limit / 1_048_576);
        throw new RuntimeException(
            'Packaging '.number_format($itemCount).' item-history files requires a PHP memory_limit of approximately '
            ."{$requiredMiB} MiB or more; {$configuredMiB} MiB is configured. "
            .'Run PHP with a higher limit (for example, php -d memory_limit=1G artisan item-history:package).',
        );
    }

    private function memoryLimitBytes(): ?int
    {
        $configured = ini_get('memory_limit');
        if (! is_string($configured)) {
            return null;
        }
        $configured = trim($configured);
        if ($configured === '' || $configured === '-1') {
            return null;
        }
        if (preg_match('/^(\d+)([KMG]?)$/iD', $configured, $matches) !== 1) {
            return null;
        }

        $bytes = (int) $matches[1];
        $power = match (strtoupper($matches[2])) {
            'G' => 3,
            'M' => 2,
            'K' => 1,
            default => 0,
        };
        for ($index = 0; $index < $power; $index++) {
            if ($bytes > intdiv(PHP_INT_MAX, 1_024)) {
                return PHP_INT_MAX;
            }
            $bytes *= 1_024;
        }

        return $bytes;
    }

    private function assertOutputOutsideArtifactData(
        string $artifactRoot,
        string $requestedOutput,
        string $prospectiveOutput,
    ): void {
        $requestedOutput = SafePath::normalize($requestedOutput);
        foreach (['datasets', 'items'] as $sourceDirectory) {
            $sourcePath = $artifactRoot.'/'.$sourceDirectory;
            if (SafePath::pathsOverlap($requestedOutput, $sourcePath)
                || SafePath::pathsOverlap($prospectiveOutput, $sourcePath)) {
                throw new RuntimeException(
                    'Item history package output cannot overlap item-history artifact data directories.',
                );
            }
        }
    }

    /**
     * @param  list<int>  $artifactVersions
     * @param  list<int>  $parserVersions
     */
    private function archiveName(
        ?string $requested,
        array $artifactVersions,
        array $parserVersions,
        string $latestGeneratedAt,
        string $datasetKey,
    ): string {
        $formatToken = implode('_', $artifactVersions);
        $parserToken = $parserVersions === [] ? 'none' : implode('_', $parserVersions);
        $name = is_string($requested) && trim($requested) !== ''
            ? trim($requested)
            : sprintf(
                'modern-allaclone-item-history-f%s-p%s-%s-%s.zip',
                $formatToken,
                $parserToken,
                substr($latestGeneratedAt, 0, 10),
                substr($datasetKey, 0, 12),
            );
        if (! str_ends_with(strtolower($name), '.zip')) {
            $name .= '.zip';
        }
        if (! ItemHistoryDataset::isSafeArchiveName($name)) {
            throw new RuntimeException('Item history archive name must be a safe portable ZIP filename.');
        }

        return $name;
    }

    private function readCurrentPointer(string $path): string
    {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('Item history CURRENT must be a regular file.');
        }
        clearstatcache(true, $path);
        $bytes = filesize($path);
        $pointer = $bytes !== false && $bytes >= 64 && $bytes <= 66 ? file_get_contents($path) : false;
        if (! is_string($pointer) || preg_match('/^([a-f0-9]{64})\r?\n?$/D', $pointer, $matches) !== 1) {
            throw new RuntimeException('Item history CURRENT contains an invalid dataset key.');
        }

        return $matches[1];
    }

    /** @return array{filename: string, dataset: string}|null */
    private function activePointer(string $artifactRoot): ?array
    {
        foreach ([
            ['CURRENT', 'Item history CURRENT'],
            ['.CURRENT.bak', 'Item history .CURRENT.bak'],
        ] as $index => [$filename, $label]) {
            $path = $artifactRoot.'/'.$filename;
            if (is_link($path)) {
                throw new RuntimeException("{$label} must be a regular file.");
            }
            if (file_exists($path)) {
                if (! is_file($path)) {
                    throw new RuntimeException("{$label} must be a regular file.");
                }
                SafePath::assertContained($path, $artifactRoot, $label);

                return [
                    'filename' => $filename,
                    'dataset' => $this->readCurrentPointer($path),
                ];
            }

            // CURRENT is authoritative. The backup is considered only when it
            // is absent, matching the repository's interrupted-swap recovery.
            if ($index === 0) {
                continue;
            }
        }

        return null;
    }

    /** @param list<string> $expected */
    private function assertExactEntries(string $directory, array $expected, string $label): void
    {
        $entries = $this->directoryEntries($directory, $label);
        sort($expected, SORT_STRING);
        if ($entries !== $expected) {
            throw new RuntimeException("The item history {$label} has missing or unexpected entries.");
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

    private function generatedInstant(string $value): ?string
    {
        foreach ([
            ['!Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s\Z'],
            ['!Y-m-d\TH:i:s.v\Z', 'Y-m-d\TH:i:s.v\Z'],
        ] as [$parse, $roundTrip]) {
            $date = DateTimeImmutable::createFromFormat($parse, $value, new DateTimeZone('UTC'));
            if ($date !== false && $date->format($roundTrip) === $value) {
                return $date->format('U.u');
            }
        }

        return null;
    }

    /** @param array<string, mixed> $value */
    private function encodeJson(array $value, string $label): string
    {
        return $this->encodeJsonValue($value, $label)."\n";
    }

    /** @param array<string, mixed> $value */
    private function encodeJsonValue(array $value, string $label): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException("Unable to encode {$label}.", 0, $exception);
        }
    }

    /** @param resource $target */
    private function writeAll($target, string $contents, string $label): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($target, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException("Unable to write {$label}.");
            }
            $offset += $written;
        }
    }

    private function writeNewFile(string $path, string $contents, string $label): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Unable to create {$label}.");
        }
        try {
            $this->writeAll($handle, $contents, $label);
            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
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
            throw new RuntimeException("Unable to atomically publish item history release asset: {$releasePath}");
        }
        if (! unlink($temporaryPath)) {
            @unlink($releasePath);
            throw new RuntimeException("Unable to finalize item history release asset: {$releasePath}");
        }
    }

    /**
     * @param  callable(string, array{path: string, owner_path: string, token: string, owner_bytes: int, owner_sha256: string}): mixed  $operation
     */
    private function withCrawlerWorkspaceLock(
        string $workspaceDirectory,
        string $artifactRoot,
        callable $operation,
    ): mixed {
        if (is_link($workspaceDirectory)) {
            throw new RuntimeException('The crawler workspace cannot be a symbolic link.');
        }
        $workspaceRoot = SafePath::existingDirectory($workspaceDirectory, 'Item history crawler workspace');
        if (SafePath::pathsOverlap($workspaceRoot, $artifactRoot)) {
            throw new RuntimeException('The crawler workspace cannot overlap the item-history artifact root.');
        }

        $statePath = $workspaceRoot.'/state';
        if (is_link($statePath)) {
            throw new RuntimeException('The crawler state directory cannot be a symbolic link.');
        }
        $stateRoot = SafePath::existingDirectory($statePath, 'Item history crawler state directory');
        SafePath::assertContained($stateRoot, $workspaceRoot, 'Item history crawler state directory');
        $lock = $this->acquireCrawlerWorkspaceLock($stateRoot);

        try {
            $result = $operation($workspaceRoot, $lock);
        } catch (Throwable $exception) {
            try {
                $this->releaseCrawlerWorkspaceLock($stateRoot, $lock);
            } catch (Throwable) {
                // Preserve the packaging failure; the surviving lock fails closed.
            }

            throw $exception;
        }

        try {
            $this->releaseCrawlerWorkspaceLock($stateRoot, $lock);
        } catch (Throwable $exception) {
            if (! is_array($result)) {
                throw $exception;
            }
            $result['warnings'] ??= [];
            $result['warnings'][] = $this->crawlerLockRecoveryWarning($stateRoot, $lock, $exception);
        }

        return $result;
    }

    /** @param array{path: string, owner_path: string, token: string, owner_bytes: int, owner_sha256: string} $lock */
    private function crawlerLockRecoveryWarning(string $stateRoot, array $lock, Throwable $exception): string
    {
        $tombstone = $stateRoot.'/.crawler.lock.package-release-'.$lock['token'];
        $survivors = array_values(array_filter(
            [$lock['path'], $tombstone],
            static fn (string $path): bool => file_exists($path) || is_link($path),
        ));
        if ($survivors === []) {
            $survivors = [$lock['path'], $tombstone];
        }

        return 'Package assets were published successfully, but crawler-lock cleanup was incomplete: '
            .$exception->getMessage().' After confirming no crawler or packager is running, manually remove the stale path: '
            .implode(' or ', array_map(
                static fn (string $path): string => SafePath::normalize($path),
                $survivors,
            ));
    }

    /** @return array{path: string, owner_path: string, token: string, owner_bytes: int, owner_sha256: string} */
    private function acquireCrawlerWorkspaceLock(string $stateRoot): array
    {
        $lockPath = $stateRoot.'/crawler.lock';
        if (file_exists($lockPath) || is_link($lockPath)) {
            throw new RuntimeException(
                'The crawler workspace is locked. Stop the crawler and verify its status before packaging flat artifacts.',
            );
        }
        if (! @mkdir($lockPath, 0700)) {
            throw new RuntimeException(
                'Unable to acquire the crawler publication lock; another crawler may be starting.',
            );
        }

        $ownerPath = $lockPath.'/owner.json';
        try {
            $resolvedLock = SafePath::assertContained($lockPath, $stateRoot, 'Crawler publication lock');
            $pid = getmypid();
            if (! is_int($pid) || $pid < 1) {
                throw new RuntimeException('Unable to identify the item-history packager process.');
            }
            $hostname = gethostname();
            if (! is_string($hostname) || trim($hostname) === '') {
                throw new RuntimeException('Unable to identify the item-history packager host.');
            }
            $token = bin2hex(random_bytes(16));
            $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s.v\Z');
            $ownerJson = $this->encodeJson([
                'schema' => 'modern-allaclone.lucy-item-crawl-lock',
                'version' => 1,
                'token' => $token,
                'runId' => "item-history-package-{$token}",
                'pid' => $pid,
                // This is a Node-compatible owner. Same-host recovery checks
                // the live PHP PID; cross-container hosts fail closed.
                'hostname' => trim($hostname),
                'acquiredAt' => $timestamp,
                'heartbeatAt' => $timestamp,
            ], 'crawler publication lock owner');
            $this->writeNewFile($ownerPath, $ownerJson, 'crawler publication lock owner');

            return [
                'path' => $resolvedLock,
                'owner_path' => SafePath::assertContained(
                    $ownerPath,
                    $resolvedLock,
                    'Crawler publication lock owner',
                ),
                'token' => $token,
                'owner_bytes' => strlen($ownerJson),
                'owner_sha256' => hash('sha256', $ownerJson),
            ];
        } catch (Throwable $exception) {
            if (is_file($ownerPath) && ! is_link($ownerPath)) {
                @unlink($ownerPath);
            }
            @rmdir($lockPath);

            throw $exception;
        }
    }

    /** @param array{path: string, owner_path: string, token: string, owner_bytes: int, owner_sha256: string} $lock */
    private function releaseCrawlerWorkspaceLock(string $stateRoot, array $lock): void
    {
        $this->assertCrawlerProofStable(['files' => [], 'lock' => $lock]);
        $this->assertExactEntries($lock['path'], ['owner.json'], 'crawler publication lock');

        $tombstone = $stateRoot.'/.crawler.lock.package-release-'.$lock['token'];
        if (file_exists($tombstone) || is_link($tombstone) || ! @rename($lock['path'], $tombstone)) {
            throw new RuntimeException('Unable to atomically release the crawler publication lock.');
        }
        $ownerPath = $tombstone.'/owner.json';
        if (is_link($ownerPath) || ! is_file($ownerPath) || ! @unlink($ownerPath) || ! @rmdir($tombstone)) {
            throw new RuntimeException('Unable to clean the released crawler publication lock.');
        }
    }

    private function portablePathsEqual(string $left, string $right): bool
    {
        $left = $this->normalizePortableAbsolutePath($left);
        $right = $this->normalizePortableAbsolutePath($right);
        if ($left === null || $right === null) {
            return false;
        }

        $windows = preg_match('/^(?:[A-Za-z]:|\/\/)/D', $left) === 1
            || preg_match('/^(?:[A-Za-z]:|\/\/)/D', $right) === 1;

        return $windows ? strtolower($left) === strtolower($right) : $left === $right;
    }

    private function normalizePortableAbsolutePath(string $path): ?string
    {
        if ($path === '' || trim($path) !== $path || str_contains($path, "\0")) {
            return null;
        }
        $path = str_replace('\\', '/', $path);
        $validShape = preg_match('#^[A-Za-z]:/(?:[^/]+(?:/[^/]+)*)?/?$#D', $path) === 1
            || preg_match('#^/(?:[^/]+(?:/[^/]+)*)?/?$#D', $path) === 1
            || preg_match('#^//[^/]+/[^/]+(?:/[^/]+)*/?$#D', $path) === 1;
        if (! $validShape || preg_match('#(?:^|/)\.\.?(?:/|$)#D', $path) === 1) {
            return null;
        }

        $normalized = rtrim($path, '/');

        return $normalized === '' || preg_match('/^[A-Za-z]:$/D', $normalized) === 1
            ? null
            : $normalized;
    }

    private function withOutputLock(string $outputRoot, callable $operation): mixed
    {
        $lockPath = $outputRoot.'/.package.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new RuntimeException('Item history package lock must be a regular file.');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock item history package publication.');
        }

        try {
            SafePath::assertContained($lockPath, $outputRoot, 'Item history package lock');

            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function withActivationLock(string $artifactRoot, callable $operation): mixed
    {
        $lockPath = $artifactRoot.'/.activation.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new RuntimeException('Item history activation lock must be a regular file.');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Unable to lock item history activation while packaging.');
        }
        try {
            $this->permissions->normalizeFile($lockPath, $artifactRoot, 'item history activation lock');
            if (! flock($lock, LOCK_SH)) {
                throw new RuntimeException('Unable to lock item history activation while packaging.');
            }
        } catch (Throwable $exception) {
            fclose($lock);

            throw $exception;
        }

        try {
            SafePath::assertContained($lockPath, $artifactRoot, 'Item history activation lock');

            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

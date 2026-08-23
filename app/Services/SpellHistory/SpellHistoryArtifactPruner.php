<?php

namespace App\Services\SpellHistory;

use JsonException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class SpellHistoryArtifactPruner
{
    private const ACTIVATION_BACKUP_FILENAME = '.CURRENT.bak';

    /**
     * @return array{
     *     apply: bool,
     *     active_dataset: string,
     *     active_pointer_age_seconds: int,
     *     keep_recent: int,
     *     minimum_age_seconds: int,
     *     datasets: list<array{dataset: string, completed_at: int, age_seconds: int, action: string}>,
     *     tombstones: list<array{entry: string, dataset: string, action: string}>,
     *     skipped: list<array{entry: string, reason: string}>,
     *     deleted_count: int,
     *     would_delete_count: int
     * }
     */
    public function prune(
        string $artifactDirectory,
        int $keepRecent = 2,
        int $minimumAgeSeconds = 86_400,
        bool $apply = false,
        ?int $now = null,
    ): array {
        if ($keepRecent < 0) {
            throw new RuntimeException('Spell history rollback retention cannot be negative.');
        }
        if ($minimumAgeSeconds < 0) {
            throw new RuntimeException('Spell history minimum age cannot be negative.');
        }
        if (is_link($artifactDirectory)) {
            throw new RuntimeException('Spell history artifact root cannot be a symbolic link.');
        }

        $artifactRoot = SafePath::existingDirectory($artifactDirectory, 'Spell history artifact root');
        $datasetsPath = $artifactRoot.'/datasets';
        if (is_link($datasetsPath)) {
            throw new RuntimeException('Spell history datasets directory cannot be a symbolic link.');
        }
        $datasetsRoot = SafePath::existingDirectory($datasetsPath, 'Spell history datasets directory');
        SafePath::assertContained($datasetsRoot, $artifactRoot, 'Spell history datasets directory');

        return $this->withPruneLock(
            $artifactRoot,
            fn (): array => $this->pruneSerialized(
                $artifactRoot,
                $datasetsRoot,
                $keepRecent,
                $minimumAgeSeconds,
                $apply,
                $now,
            ),
        );
    }

    /** @return array<string, mixed> */
    private function pruneSerialized(
        string $artifactRoot,
        string $datasetsRoot,
        int $keepRecent,
        int $minimumAgeSeconds,
        bool $apply,
        ?int $now,
    ): array {
        $now ??= time();
        $activePointer = $this->withActivationLock(
            $artifactRoot,
            fn (): array => $this->readActivePointer($artifactRoot),
        );
        $activeKey = $activePointer['dataset'];
        $activePointerAge = max(0, $now - $activePointer['modified_at']);
        [$validDatasets, $skipped] = $this->discoverDatasets(
            $datasetsRoot,
            $activeKey,
        );
        [$tombstones, $tombstoneSkipped] = $this->discoverTombstones($artifactRoot);
        $skipped = [...$skipped, ...$tombstoneSkipped];

        if (! isset($validDatasets[$activeKey])) {
            throw new RuntimeException('The active spell history dataset is missing from the datasets directory.');
        }

        $inactive = array_values(array_filter(
            $validDatasets,
            static fn (array $dataset): bool => $dataset['dataset'] !== $activeKey,
        ));
        usort($inactive, static fn (array $left, array $right): int => [
            $right['completed_at'],
            $right['dataset'],
        ] <=> [
            $left['completed_at'],
            $left['dataset'],
        ]);
        $rollbackKeys = array_fill_keys(
            array_column(array_slice($inactive, 0, $keepRecent), 'dataset'),
            true,
        );

        $datasets = [[
            'dataset' => $activeKey,
            'completed_at' => $validDatasets[$activeKey]['completed_at'],
            'age_seconds' => max(0, $now - $validDatasets[$activeKey]['completed_at']),
            'action' => 'kept_active',
        ]];
        $deletionTargets = [];
        foreach ($inactive as $dataset) {
            $age = max(0, $now - $dataset['completed_at']);
            $action = match (true) {
                isset($rollbackKeys[$dataset['dataset']]) => 'kept_rollback',
                $dataset['completed_at'] >= $activePointer['modified_at']
                    && $age < $minimumAgeSeconds => 'kept_pending_activation',
                $activePointerAge < $minimumAgeSeconds => 'kept_activation_grace',
                $age < $minimumAgeSeconds => 'kept_minimum_age',
                default => $apply ? 'deleted' : 'would_delete',
            };
            if (in_array($action, ['deleted', 'would_delete'], true)) {
                $deletionTargets[] = $dataset;
            }
            $datasets[] = [
                'dataset' => $dataset['dataset'],
                'completed_at' => $dataset['completed_at'],
                'age_seconds' => $age,
                'action' => $action,
            ];
        }

        // Tree traversal may take minutes for a full dataset, so an inexpensive
        // dry run stops at manifest/completion validation. An applied run walks
        // every target without holding the activation lock used by web requests.
        if ($apply) {
            foreach ([...$deletionTargets, ...$tombstones] as $dataset) {
                $this->preflightTree($dataset['path'], $dataset['path']);
            }
        }

        $newTombstones = [];
        if ($apply && $deletionTargets !== []) {
            $newTombstones = $this->withActivationLock(
                $artifactRoot,
                fn (): array => $this->tombstoneDatasets(
                    $artifactRoot,
                    $datasetsRoot,
                    $activePointer,
                    $deletionTargets,
                ),
            );
        }

        $allTombstones = [...$tombstones, ...$newTombstones];
        if ($apply) {
            foreach ($allTombstones as $tombstone) {
                $this->validateDataset($tombstone['path'], $tombstone['dataset']);
                $this->preflightTree($tombstone['path'], $tombstone['path']);
                $this->deleteTree($tombstone['path'], $tombstone['path']);
            }
        }

        $tombstoneResults = array_map(static fn (array $tombstone): array => [
            'entry' => basename($tombstone['path']),
            'dataset' => $tombstone['dataset'],
            'action' => $apply ? 'deleted_tombstone' : 'would_delete_tombstone',
        ], $tombstones);

        return [
            'apply' => $apply,
            'active_dataset' => $activeKey,
            'active_pointer_age_seconds' => $activePointerAge,
            'keep_recent' => $keepRecent,
            'minimum_age_seconds' => $minimumAgeSeconds,
            'datasets' => $datasets,
            'tombstones' => $tombstoneResults,
            'skipped' => $skipped,
            'deleted_count' => $apply ? count($allTombstones) : 0,
            'would_delete_count' => $apply ? 0 : count($deletionTargets) + count($tombstones),
        ];
    }

    /**
     * @return array{
     *     0: array<string, array{dataset: string, path: string, completed_at: int, format_version: int, canonical_format_version: int}>,
     *     1: list<array{entry: string, reason: string}>
     * }
     */
    private function discoverDatasets(string $datasetsRoot, string $activeKey): array
    {
        $entries = scandir($datasetsRoot);
        if ($entries === false) {
            throw new RuntimeException('Unable to enumerate spell history datasets.');
        }

        $validDatasets = [];
        $skipped = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $datasetsRoot.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path)) {
                throw new RuntimeException("Spell history dataset entries cannot be symbolic links: {$entry}");
            }
            if (preg_match(SpellHistoryArtifact::DATASET_KEY_PATTERN, $entry) !== 1) {
                $skipped[] = ['entry' => $entry, 'reason' => 'unrecognized dataset name'];

                continue;
            }
            if (! is_dir($path)) {
                $skipped[] = ['entry' => $entry, 'reason' => 'dataset entry is not a directory'];

                continue;
            }

            $resolved = SafePath::assertContained($path, $datasetsRoot, "Spell history dataset {$entry}");
            try {
                $validDatasets[$entry] = $this->validateDataset($resolved, $entry, $entry === $activeKey);
            } catch (UnexpectedValueException $exception) {
                if ($entry === $activeKey) {
                    throw new RuntimeException(
                        "The active spell history dataset {$entry} is incomplete or invalid: {$exception->getMessage()}",
                        0,
                        $exception,
                    );
                }
                $skipped[] = ['entry' => $entry, 'reason' => $exception->getMessage()];
            }
        }

        return [$validDatasets, $skipped];
    }

    /**
     * @return array{
     *     0: list<array{dataset: string, path: string, completed_at: int, format_version: int, canonical_format_version: int}>,
     *     1: list<array{entry: string, reason: string}>
     * }
     */
    private function discoverTombstones(string $artifactRoot): array
    {
        $entries = scandir($artifactRoot);
        if ($entries === false) {
            throw new RuntimeException('Unable to enumerate spell history artifact root.');
        }

        $tombstones = [];
        $skipped = [];
        foreach ($entries as $entry) {
            if (! str_starts_with($entry, '.pruning-')) {
                continue;
            }

            $path = $artifactRoot.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path)) {
                throw new RuntimeException("Spell history pruning tombstones cannot be symbolic links: {$entry}");
            }
            if (preg_match('/^\.pruning-([a-f0-9]{64})-([a-f0-9]{32})$/D', $entry, $matches) !== 1) {
                $skipped[] = ['entry' => $entry, 'reason' => 'unrecognized pruning tombstone name'];

                continue;
            }
            if (! is_dir($path)) {
                $skipped[] = ['entry' => $entry, 'reason' => 'pruning tombstone is not a directory'];

                continue;
            }

            $resolved = SafePath::assertContained($path, $artifactRoot, "Spell history pruning tombstone {$entry}");
            try {
                $tombstones[] = $this->validateDataset($resolved, $matches[1]);
            } catch (UnexpectedValueException $exception) {
                $skipped[] = ['entry' => $entry, 'reason' => $exception->getMessage()];
            }
        }

        return [$tombstones, $skipped];
    }

    /**
     * @param  array{dataset: string, modified_at: int, pointer: string}  $plannedPointer
     * @param  list<array{dataset: string, path: string, completed_at: int, format_version: int, canonical_format_version: int}>  $targets
     * @return list<array{dataset: string, path: string, completed_at: int, format_version: int, canonical_format_version: int}>
     */
    private function tombstoneDatasets(
        string $artifactRoot,
        string $datasetsRoot,
        array $plannedPointer,
        array $targets,
    ): array {
        $currentPointer = $this->readActivePointer($artifactRoot);
        if ($currentPointer !== $plannedPointer) {
            throw new RuntimeException('Spell history activation changed while pruning was planned.');
        }

        $moved = [];
        try {
            foreach ($targets as $dataset) {
                $source = $datasetsRoot.'/'.$dataset['dataset'];
                if ($dataset['dataset'] === $currentPointer['dataset']
                    || is_link($source) || ! is_dir($source)) {
                    throw new RuntimeException("Spell history prune target became active or unsafe: {$dataset['dataset']}");
                }
                $resolved = SafePath::assertContained($source, $datasetsRoot, 'Spell history prune target');
                if ($resolved !== $dataset['path']) {
                    throw new RuntimeException("Spell history prune target changed after validation: {$dataset['dataset']}");
                }

                do {
                    $tombstone = $artifactRoot.'/.pruning-'.$dataset['dataset'].'-'.bin2hex(random_bytes(16));
                } while (file_exists($tombstone) || is_link($tombstone));
                if (! rename($resolved, $tombstone)) {
                    throw new RuntimeException("Unable to tombstone inactive spell history dataset: {$dataset['dataset']}");
                }

                $moved[] = [
                    ...$dataset,
                    'path' => SafePath::assertContained(
                        $tombstone,
                        $artifactRoot,
                        'Spell history pruning tombstone',
                    ),
                    'original_path' => $resolved,
                ];
            }
        } catch (Throwable $exception) {
            $rollbackFailed = false;
            foreach (array_reverse($moved) as $dataset) {
                if (is_dir($dataset['path']) && ! file_exists($dataset['original_path'])
                    && ! rename($dataset['path'], $dataset['original_path'])) {
                    $rollbackFailed = true;
                }
            }
            if ($rollbackFailed) {
                throw new RuntimeException(
                    'Spell history pruning could not restore every tombstoned dataset; a later prune can clean the .pruning-* entries.',
                    0,
                    $exception,
                );
            }

            throw $exception;
        }

        return array_map(static function (array $dataset): array {
            unset($dataset['original_path']);

            return $dataset;
        }, $moved);
    }

    /** @return array{dataset: string, path: string, completed_at: int, format_version: int, canonical_format_version: int} */
    private function validateDataset(string $directory, string $datasetKey, bool $requireCurrentFormat = false): array
    {
        $manifestPath = $directory.'/manifest.json';
        $completionPath = $directory.'/COMPLETE.json';
        $manifest = $this->decodeJsonFile(
            $manifestPath,
            SpellHistoryArtifact::MAX_MANIFEST_BYTES,
            'manifest',
            $directory,
        );
        $completion = $this->decodeJsonFile(
            $completionPath,
            SpellHistoryArtifact::MAX_COMPLETION_BYTES,
            'completion marker',
            $directory,
        );
        $manifestVersions = $this->assertArtifactHeader($manifest, 'manifest', $datasetKey);
        $completionVersions = $this->assertArtifactHeader($completion, 'completion', $datasetKey);
        if ($manifestVersions !== $completionVersions) {
            throw new UnexpectedValueException('manifest and completion marker format versions differ');
        }
        if ($requireCurrentFormat
            && ($manifestVersions['format_version'] !== SpellHistoryArtifact::FORMAT_VERSION
                || $manifestVersions['canonical_format_version'] !== SpellCanonicalizer::FORMAT_VERSION)) {
            throw new UnexpectedValueException('active dataset uses an incompatible format');
        }

        $snapshots = $manifest['snapshots'] ?? null;
        $stats = $manifest['stats'] ?? null;
        if (! is_array($snapshots) || ! array_is_list($snapshots) || ! is_array($stats)
            || ($stats['snapshot_count'] ?? null) !== count($snapshots)
            || ! is_int($stats['spell_count'] ?? null) || $stats['spell_count'] < 1
            || ! is_int($stats['revision_count'] ?? null) || $stats['revision_count'] < $stats['spell_count']) {
            throw new UnexpectedValueException('manifest stats are incomplete');
        }

        clearstatcache(true, $manifestPath);
        $manifestHash = hash_file('sha256', $manifestPath);
        $manifestBytes = filesize($manifestPath);
        $expectedHash = $completion['manifest_sha256'] ?? null;
        if ($manifestHash === false || $manifestBytes === false
            || ! is_string($expectedHash)
            || preg_match(SpellHistoryArtifact::DATASET_KEY_PATTERN, $expectedHash) !== 1
            || ! hash_equals($manifestHash, $expectedHash)
            || ($completion['manifest_bytes'] ?? null) !== $manifestBytes
            || ($completion['spell_count'] ?? null) !== $stats['spell_count']
            || ($completion['revision_count'] ?? null) !== $stats['revision_count']) {
            throw new UnexpectedValueException('completion marker does not match its manifest');
        }

        clearstatcache(true, $completionPath);
        $completedAt = filemtime($completionPath);
        if ($completedAt === false) {
            throw new UnexpectedValueException('completion marker timestamp is unavailable');
        }

        return [
            'dataset' => $datasetKey,
            'path' => $directory,
            'completed_at' => $completedAt,
            ...$manifestVersions,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeJsonFile(
        string $path,
        int $maximumBytes,
        string $label,
        string $datasetRoot,
    ): array {
        if (is_link($path)) {
            throw new RuntimeException("Spell history dataset {$label} cannot be a symbolic link.");
        }
        if (! is_file($path)) {
            throw new UnexpectedValueException("missing {$label}");
        }

        $resolved = SafePath::assertContained($path, $datasetRoot, "Spell history dataset {$label}");
        $bytes = filesize($resolved);
        if ($bytes === false || $bytes < 2 || $bytes > $maximumBytes) {
            throw new UnexpectedValueException("invalid {$label} size");
        }
        $json = file_get_contents($resolved);
        if ($json === false) {
            throw new UnexpectedValueException("unable to read {$label}");
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException("invalid JSON in {$label}", 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new UnexpectedValueException("invalid {$label} payload");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array{format_version: int, canonical_format_version: int}
     */
    private function assertArtifactHeader(array $artifact, string $type, string $datasetKey): array
    {
        $formatVersion = $artifact['format_version'] ?? null;
        $canonicalFormatVersion = $artifact['canonical_format_version'] ?? null;
        if (($artifact['schema'] ?? null) !== SpellHistoryArtifact::SCHEMA
            || ($artifact['artifact_type'] ?? null) !== $type
            || ! is_int($formatVersion) || $formatVersion < 1
            || ! is_int($canonicalFormatVersion) || $canonicalFormatVersion < 1
            || ($artifact['dataset'] ?? null) !== $datasetKey) {
            throw new UnexpectedValueException("incompatible {$type} format");
        }

        return [
            'format_version' => $formatVersion,
            'canonical_format_version' => $canonicalFormatVersion,
        ];
    }

    /** @return array{dataset: string, modified_at: int, pointer: string} */
    private function readActivePointer(string $artifactRoot): array
    {
        $currentPath = $artifactRoot.'/CURRENT';
        if (file_exists($currentPath) || is_link($currentPath)) {
            return $this->decodePointer($currentPath, 'CURRENT', $artifactRoot);
        }

        $backupPath = $artifactRoot.'/'.self::ACTIVATION_BACKUP_FILENAME;
        if (! file_exists($backupPath) && ! is_link($backupPath)) {
            throw new RuntimeException('Spell history CURRENT is missing and no recovery pointer is available.');
        }

        return $this->decodePointer($backupPath, self::ACTIVATION_BACKUP_FILENAME, $artifactRoot);
    }

    /** @return array{dataset: string, modified_at: int, pointer: string} */
    private function decodePointer(string $path, string $label, string $artifactRoot): array
    {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException("Spell history {$label} must be a regular file.");
        }
        $resolved = SafePath::assertContained($path, $artifactRoot, "Spell history {$label}");
        $size = filesize($resolved);
        if ($size === false || $size < 64 || $size > 66) {
            throw new RuntimeException("Spell history {$label} has an invalid size.");
        }
        $raw = file_get_contents($resolved);
        if ($raw === false) {
            throw new RuntimeException("Unable to read spell history {$label}.");
        }

        $key = rtrim($raw, "\r\n");
        if (($raw !== $key && $raw !== $key."\n" && $raw !== $key."\r\n")
            || preg_match(SpellHistoryArtifact::DATASET_KEY_PATTERN, $key) !== 1) {
            throw new RuntimeException("Spell history {$label} contains an invalid dataset key.");
        }

        clearstatcache(true, $resolved);
        $modifiedAt = filemtime($resolved);
        if ($modifiedAt === false) {
            throw new RuntimeException("Unable to read spell history {$label} timestamp.");
        }

        return ['dataset' => $key, 'modified_at' => $modifiedAt, 'pointer' => $label];
    }

    private function preflightTree(string $directory, string $datasetRoot): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException("Unable to enumerate spell history dataset: {$directory}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path)) {
                throw new RuntimeException("Refusing to prune a dataset containing a symbolic link: {$path}");
            }
            SafePath::assertContained($path, $datasetRoot, 'Spell history prune target');
            if (is_file($path)) {
                continue;
            }
            if (! is_dir($path)) {
                throw new RuntimeException("Refusing to prune an unexpected dataset entry: {$path}");
            }

            $this->preflightTree($path, $datasetRoot);
        }
    }

    private function deleteTree(string $directory, string $datasetRoot): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException("Unable to enumerate spell history dataset: {$directory}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path)) {
                throw new RuntimeException("Refusing to prune a dataset containing a symbolic link: {$path}");
            }
            SafePath::assertContained($path, $datasetRoot, 'Spell history prune target');
            if (is_file($path)) {
                if (! unlink($path)) {
                    throw new RuntimeException("Unable to remove spell history artifact: {$path}");
                }

                continue;
            }
            if (! is_dir($path)) {
                throw new RuntimeException("Refusing to prune an unexpected dataset entry: {$path}");
            }

            $this->deleteTree($path, $datasetRoot);
        }

        if (! rmdir($directory)) {
            throw new RuntimeException("Unable to remove spell history dataset directory: {$directory}");
        }
    }

    private function withPruneLock(string $artifactRoot, callable $operation): mixed
    {
        $lockPath = $artifactRoot.'/.prune.lock';
        if (is_link($lockPath)
            || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new RuntimeException('Spell history prune lock must be a regular file.');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock spell history pruning.');
        }

        try {
            SafePath::assertContained($lockPath, $artifactRoot, 'Spell history prune lock');

            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function withActivationLock(string $artifactRoot, callable $operation): mixed
    {
        $lockPath = $artifactRoot.'/.activation.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Spell history activation lock cannot be a symbolic link.');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock spell history activation for pruning.');
        }

        try {
            SafePath::assertContained($lockPath, $artifactRoot, 'Spell history activation lock');

            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

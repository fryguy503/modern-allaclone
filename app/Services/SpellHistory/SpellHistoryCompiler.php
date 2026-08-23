<?php

namespace App\Services\SpellHistory;

use JsonException;
use RuntimeException;
use Throwable;

final class SpellHistoryCompiler
{
    private const MAX_LOGICAL_RECORD_BYTES = 8_388_608;

    private const ACTIVATION_BACKUP_FILENAME = '.CURRENT.bak';

    public function __construct(
        private readonly SnapshotLocator $snapshotLocator,
        private readonly SpellCanonicalizer $canonicalizer,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void|null  $progress
     * @return array{dataset: string, snapshots: int, spells: int, revisions: int, bytes: int, reused: bool, path: string}
     */
    public function compile(string $sourceDirectory, string $outputDirectory, ?callable $progress = null): array
    {
        $this->canonicalizer->resetCompilationState();
        $snapshots = $this->snapshotLocator->locate($sourceDirectory);
        $ignoredTextFiles = $this->snapshotLocator->ignoredTextFiles();
        $sourceRoot = SafePath::existingDirectory($sourceDirectory, 'Snapshot source');
        $prospectiveOutput = SafePath::prospectiveDirectory($outputDirectory, 'Spell history output');

        if (SafePath::pathsOverlap($sourceRoot, $prospectiveOutput)) {
            throw new RuntimeException('Snapshot source and spell history output directories cannot overlap.');
        }

        $outputRoot = SafePath::createDirectory($outputDirectory, 'Spell history output');
        $datasetsPath = $outputRoot.'/datasets';
        if (is_link($datasetsPath)) {
            throw new RuntimeException('Spell history datasets directory cannot be a symbolic link.');
        }
        $datasetsRoot = SafePath::createDirectory($datasetsPath, 'Spell history datasets directory');
        SafePath::assertContained($datasetsRoot, $outputRoot, 'Spell history datasets directory');
        $sourceMetadata = $this->hashSnapshots($snapshots, $progress);
        $snapshotHealth = $this->assessSnapshotHealth($sourceMetadata);
        $datasetKey = $this->datasetKey($snapshots, $sourceMetadata, $ignoredTextFiles);
        $finalDirectory = $datasetsRoot.'/'.$datasetKey;

        if (is_link($finalDirectory)) {
            throw new RuntimeException('Spell history dataset targets cannot be symbolic links.');
        }
        if (file_exists($finalDirectory)) {
            $this->validateExistingDataset(
                $finalDirectory,
                $datasetsRoot,
                $datasetKey,
                $snapshots,
                $sourceMetadata,
            );
            $this->activate($outputRoot, $datasetKey);
            $manifest = $this->decodeJsonFile(
                $finalDirectory.'/manifest.json',
                SpellHistoryArtifact::MAX_MANIFEST_BYTES,
                'existing spell history manifest',
            );

            return $this->resultFromManifest($manifest, $finalDirectory, true);
        }

        $stageDirectory = $outputRoot.'/.staging-'.bin2hex(random_bytes(16));
        if (! mkdir($stageDirectory, 0755, true)) {
            throw new RuntimeException("Unable to create staging directory: {$stageDirectory}");
        }

        $streams = [];
        try {
            foreach ($snapshots as $index => $snapshot) {
                $streams[] = new CsvSnapshotStream(
                    $snapshot,
                    $this->canonicalizer,
                    $sourceMetadata[$index]['encoding'],
                );
            }
            if ($progress !== null) {
                foreach ($ignoredTextFiles as $filename) {
                    $progress('warning', [
                        'message' => "Ignored non-snapshot text file in source directory: {$filename}",
                    ]);
                }
                foreach ($this->canonicalizer->unclassifiedHeaders() as $rawHeader => $canonicalField) {
                    $progress('warning', [
                        'message' => "Unclassified header {$rawHeader} is preserved as text ({$canonicalField}).",
                    ]);
                }
                foreach ($snapshotHealth as $index => $health) {
                    if ($health['trusted_for_absence_confirmation']) {
                        continue;
                    }

                    $progress('warning', [
                        'message' => sprintf(
                            'Snapshot %s has an anomalously low record count; it remains readable but cannot confirm spell removals.',
                            $snapshots[$index]->filename,
                        ),
                    ]);
                }
            }

            $snapshotManifest = $this->initialSnapshotManifest($streams, $sourceMetadata, $snapshotHealth);
            $canonicalFieldSet = [];
            foreach ($streams as $stream) {
                foreach ($stream->canonicalFields() as $field) {
                    $canonicalFieldSet[$field] = true;
                }
            }

            $spellCount = 0;
            $revisionCount = 0;
            $artifactBytes = 0;

            while (($spellId = $this->minimumCurrentId($streams)) !== null) {
                $rows = array_fill(0, count($streams), null);
                $rawRows = array_fill(0, count($streams), null);
                foreach ($streams as $index => $stream) {
                    if ($stream->currentId() !== $spellId) {
                        continue;
                    }

                    $rows[$index] = $stream->currentRow();
                    $rawRows[$index] = $stream->currentRawRow();
                    $stream->advance();
                }

                $artifact = $this->buildSpellArtifact(
                    $datasetKey,
                    $spellId,
                    $rows,
                    $rawRows,
                    $snapshots,
                    $snapshotHealth,
                );
                $artifactBytes += $this->writeSpellArtifact($stageDirectory, $spellId, $artifact);
                $spellCount++;
                $revisionCount += count($artifact['revisions']);

                if ($progress !== null && $spellCount % 5_000 === 0) {
                    $progress('compile', ['spells' => $spellCount, 'revisions' => $revisionCount]);
                }
            }

            foreach ($streams as $index => $stream) {
                if ($stream->rowCount() !== $sourceMetadata[$index]['logical_row_count']) {
                    throw new RuntimeException(
                        "Snapshot logical row count changed while compiling: {$snapshots[$index]->filename}"
                    );
                }
                $snapshotManifest[$index]['row_count'] = $stream->rowCount();
                $snapshotManifest[$index]['canonical_sha256'] = $stream->canonicalDigest();
                $snapshotManifest[$index]['encoding'] = $stream->detectedEncoding();
                $stream->close();
            }
            $seenCanonicalSnapshots = [];
            foreach ($snapshotManifest as $index => $snapshotEntry) {
                $digest = $snapshotEntry['canonical_sha256'];
                $snapshotManifest[$index]['canonical_duplicate_of'] = $seenCanonicalSnapshots[$digest] ?? null;
                $seenCanonicalSnapshots[$digest] ??= $snapshotEntry['key'];
            }
            $streams = [];
            $this->verifySnapshotsUnchanged($snapshots, $sourceMetadata, $progress);

            $canonicalFields = array_keys($canonicalFieldSet);
            sort($canonicalFields, SORT_NATURAL);
            $manifest = [
                'schema' => SpellHistoryArtifact::SCHEMA,
                'artifact_type' => 'manifest',
                'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
                'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
                'dataset' => $datasetKey,
                'adapter' => $this->canonicalizer->adapterMetadata(),
                'unclassified_headers' => $this->canonicalizer->unclassifiedHeaders(),
                'ignored_source_files' => $ignoredTextFiles,
                'snapshots' => $snapshotManifest,
                'canonical_fields' => $canonicalFields,
                'stats' => [
                    'snapshot_count' => count($snapshots),
                    'spell_count' => $spellCount,
                    'revision_count' => $revisionCount,
                    'source_bytes' => array_sum(array_column($sourceMetadata, 'bytes')),
                    'untrusted_snapshot_count' => count(array_filter(
                        $snapshotHealth,
                        static fn (array $health): bool => ! $health['trusted_for_absence_confirmation'],
                    )),
                    'spell_payload_bytes' => $artifactBytes,
                    'artifact_bytes' => 0,
                ],
            ];

            $manifestBytes = 0;
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $manifestBytes = $this->encodedJsonLength($manifest, SpellHistoryArtifact::MAX_MANIFEST_BYTES, 'manifest');
                $totalBytes = $artifactBytes + $manifestBytes;
                if ($manifest['stats']['artifact_bytes'] === $totalBytes) {
                    break;
                }
                $manifest['stats']['artifact_bytes'] = $totalBytes;
            }
            if ($manifest['stats']['artifact_bytes'] !== $artifactBytes + $manifestBytes) {
                throw new RuntimeException('Unable to stabilize the spell history manifest byte count.');
            }
            $manifestBytes = $this->writeJsonFile(
                $stageDirectory.'/manifest.json',
                $manifest,
                SpellHistoryArtifact::MAX_MANIFEST_BYTES,
                'manifest',
            );
            $this->writeCompletionMarker($stageDirectory, $manifest, $manifestBytes);

            if (! rename($stageDirectory, $finalDirectory)) {
                throw new RuntimeException("Unable to promote staged dataset {$datasetKey}.");
            }

            $this->activate($outputRoot, $datasetKey);
            if ($progress !== null) {
                $progress('complete', ['dataset' => $datasetKey, 'spells' => $spellCount, 'revisions' => $revisionCount]);
            }

            return [
                'dataset' => $datasetKey,
                'snapshots' => count($snapshots),
                'spells' => $spellCount,
                'revisions' => $revisionCount,
                'bytes' => $manifest['stats']['artifact_bytes'],
                'reused' => false,
                'path' => SafePath::normalize($finalDirectory),
            ];
        } catch (Throwable $exception) {
            foreach ($streams as $stream) {
                $stream->close();
            }
            if (is_link($stageDirectory)) {
                unlink($stageDirectory);
            } elseif (is_dir($stageDirectory)) {
                $this->removeStagingDirectory($stageDirectory, $outputRoot);
            }

            throw $exception;
        }
    }

    /**
     * @param  list<SnapshotFile>  $snapshots
     * @param  callable(string, array<string, mixed>): void|null  $progress
     * @return list<array{sha256: string, bytes: int, encoding: string, physical_line_count: int, logical_row_count: int}>
     */
    private function hashSnapshots(array $snapshots, ?callable $progress): array
    {
        $metadata = [];
        foreach ($snapshots as $index => $snapshot) {
            $handle = fopen($snapshot->path, 'rb');
            if ($handle === false) {
                throw new RuntimeException("Unable to analyze snapshot: {$snapshot->filename}");
            }

            $hash = hash_init('sha256');
            $validator = new Utf8StreamValidator;
            $bytes = 0;
            $physicalLines = 0;
            $lastByte = null;
            try {
                while (! feof($handle)) {
                    $chunk = fread($handle, 1_048_576);
                    if ($chunk === false) {
                        throw new RuntimeException("Unable to read snapshot: {$snapshot->filename}");
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    $bytes += strlen($chunk);
                    $physicalLines += substr_count($chunk, "\n");
                    $lastByte = $chunk[strlen($chunk) - 1];
                    hash_update($hash, $chunk);
                    $validator->consume($chunk);
                }
            } finally {
                fclose($handle);
            }
            if ($bytes > 0 && $lastByte !== "\n") {
                $physicalLines++;
            }

            $encoding = $validator->isValidAtEnd() ? 'utf-8' : 'windows-1252';
            $metadata[] = [
                'sha256' => hash_final($hash),
                'bytes' => $bytes,
                'encoding' => $encoding,
                'physical_line_count' => $physicalLines,
                'logical_row_count' => $this->countLogicalRows($snapshot, $encoding),
            ];
            if ($progress !== null) {
                $progress('hash', [
                    'current' => $index + 1,
                    'total' => count($snapshots),
                    'file' => $snapshot->filename,
                ]);
            }
        }

        return $metadata;
    }

    private function countLogicalRows(SnapshotFile $snapshot, string $encoding): int
    {
        $handle = fopen($snapshot->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to inspect snapshot rows: {$snapshot->filename}");
        }
        if ($encoding === 'windows-1252'
            && stream_filter_append($handle, 'convert.iconv.Windows-1252/UTF-8', STREAM_FILTER_READ) === false) {
            fclose($handle);
            throw new RuntimeException("Unable to inspect Windows-1252 rows in {$snapshot->filename}.");
        }

        try {
            $header = fgetcsv($handle, self::MAX_LOGICAL_RECORD_BYTES + 1, ',', '"', '');
            if ($header === false) {
                throw new RuntimeException("Snapshot has no CSV header: {$snapshot->filename}");
            }
            $columnCount = count($header);
            $rows = 0;
            while (($record = fgetcsv($handle, self::MAX_LOGICAL_RECORD_BYTES + 1, ',', '"', '')) !== false) {
                if ($this->blankCsvRecord($record)) {
                    continue;
                }
                if (count($record) !== $columnCount) {
                    throw new RuntimeException(
                        "CSV row width does not match its header in {$snapshot->filename} near logical record ".($rows + 2).'.'
                    );
                }

                $rows++;
            }
            if (! feof($handle)) {
                throw new RuntimeException("Unable to inspect CSV rows in {$snapshot->filename}.");
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string|null> $record */
    private function blankCsvRecord(array $record): bool
    {
        foreach ($record as $value) {
            if ($value !== null && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Identify logical-record valleys without treating small natural changes as
     * damage. An anomalously low capture remains untrusted until it recovers to
     * near the last trusted reference; two partial recoveries therefore cannot
     * confirm mass removals. Untrusted captures still contribute value changes.
     *
     * @param  list<array{logical_row_count: int}>  $sourceMetadata
     * @return list<array{trusted_for_absence_confirmation: bool, status: string, logical_row_count: int}>
     */
    private function assessSnapshotHealth(array $sourceMetadata): array
    {
        $health = [];
        $lastTrustedReference = null;
        $recoveryRequired = false;
        foreach ($sourceMetadata as $metadata) {
            $count = $metadata['logical_row_count'];
            $materiallyBelowTrustedReference = $lastTrustedReference !== null
                && $count * 100 < $lastTrustedReference * 98
                && ($recoveryRequired || $lastTrustedReference - $count >= 1_000);
            $trusted = ! $materiallyBelowTrustedReference;
            if ($trusted) {
                $lastTrustedReference = $count;
                $recoveryRequired = false;
            } else {
                $recoveryRequired = true;
            }

            $health[] = [
                'trusted_for_absence_confirmation' => $trusted,
                'status' => $trusted ? 'healthy' : 'anomalously_low_record_count',
                'logical_row_count' => $count,
            ];
        }

        return $health;
    }

    /**
     * Re-hash after parsing so mutable source bytes can never be published under
     * a dataset key derived from a different version of the archive.
     *
     * @param  list<SnapshotFile>  $snapshots
     * @param  list<array{sha256: string, bytes: int, encoding: string}>  $sourceMetadata
     * @param  callable(string, array<string, mixed>): void|null  $progress
     */
    private function verifySnapshotsUnchanged(array $snapshots, array $sourceMetadata, ?callable $progress): void
    {
        foreach ($snapshots as $index => $snapshot) {
            $checksum = hash_file('sha256', $snapshot->path);
            $bytes = filesize($snapshot->path);
            if ($checksum === false || $bytes === false
                || $checksum !== $sourceMetadata[$index]['sha256']
                || $bytes !== $sourceMetadata[$index]['bytes']) {
                throw new RuntimeException("Snapshot changed while it was being compiled: {$snapshot->filename}");
            }
            if ($progress !== null) {
                $progress('verify', [
                    'current' => $index + 1,
                    'total' => count($snapshots),
                    'file' => $snapshot->filename,
                ]);
            }
        }
    }

    /**
     * @param  list<SnapshotFile>  $snapshots
     * @param  list<array{sha256: string, bytes: int, encoding: string}>  $sourceMetadata
     */
    private function datasetKey(array $snapshots, array $sourceMetadata, array $ignoredTextFiles): string
    {
        $context = hash_init('sha256');
        hash_update($context, SpellHistoryArtifact::SCHEMA."\0".SpellHistoryArtifact::FORMAT_VERSION."\0".SpellCanonicalizer::FORMAT_VERSION."\0");

        foreach ($snapshots as $index => $snapshot) {
            hash_update($context, $snapshot->filename."\0".$sourceMetadata[$index]['sha256']."\0");
        }
        foreach ($ignoredTextFiles as $filename) {
            hash_update($context, "ignored\0{$filename}\0");
        }

        return hash_final($context);
    }

    /**
     * @param  list<CsvSnapshotStream>  $streams
     * @param  list<array{sha256: string, bytes: int, encoding: string, physical_line_count: int, logical_row_count: int}>  $sourceMetadata
     * @param  list<array{trusted_for_absence_confirmation: bool, status: string, logical_row_count: int}>  $snapshotHealth
     * @return list<array<string, mixed>>
     */
    private function initialSnapshotManifest(
        array $streams,
        array $sourceMetadata,
        array $snapshotHealth,
    ): array {
        $manifest = [];
        foreach ($streams as $index => $stream) {
            $snapshot = $stream->snapshot();
            $manifest[] = [
                'sequence' => $index + 1,
                'key' => $snapshot->key,
                'observed_at' => $snapshot->observedAtIso(),
                'previous_snapshot' => $index > 0 ? $streams[$index - 1]->snapshot()->key : null,
                'source_file' => $snapshot->filename,
                'source_sha256' => $sourceMetadata[$index]['sha256'],
                'source_bytes' => $sourceMetadata[$index]['bytes'],
                'source_physical_line_count' => $sourceMetadata[$index]['physical_line_count'],
                'health_status' => $snapshotHealth[$index]['status'],
                'trusted_for_absence_confirmation' => $snapshotHealth[$index]['trusted_for_absence_confirmation'],
                'schema_sha256' => $stream->schemaHash(),
                'encoding' => $sourceMetadata[$index]['encoding'],
                'canonical_field_count' => count($stream->canonicalFields()),
                'row_count' => $sourceMetadata[$index]['logical_row_count'],
            ];
        }

        return $manifest;
    }

    /** @param list<CsvSnapshotStream> $streams */
    private function minimumCurrentId(array $streams): ?int
    {
        $minimum = null;
        foreach ($streams as $stream) {
            $id = $stream->currentId();
            if ($id !== null && ($minimum === null || $id < $minimum)) {
                $minimum = $id;
            }
        }

        return $minimum;
    }

    /**
     * @param  list<array<string, int|float|string|null>|null>  $rows
     * @param  list<array<string, string|null>|null>  $rawRows
     * @param  list<SnapshotFile>  $snapshots
     * @param  list<array{trusted_for_absence_confirmation: bool, status: string, logical_row_count: int}>  $snapshotHealth
     * @return array<string, mixed>
     */
    private function buildSpellArtifact(
        string $datasetKey,
        int $spellId,
        array $rows,
        array $rawRows,
        array $snapshots,
        array $snapshotHealth,
    ): array {
        $revisions = [];
        $absenceRanges = [];
        $knownState = [];
        $knownRawState = [];
        $knownSnapshotByField = [];
        $everObserved = false;
        $missingCaptureCount = 0;
        $trustedMissingCaptureCount = 0;
        $firstMissingIndex = null;
        $firstMissingSnapshot = null;
        $firstObservedAt = null;
        $lastObservedAt = null;
        $latestName = null;
        $latestIcon = null;

        foreach ($snapshots as $index => $snapshot) {
            $row = $rows[$index];
            $previousSnapshot = $index > 0 ? $snapshots[$index - 1]->key : null;

            if ($row === null) {
                if ($everObserved) {
                    $missingCaptureCount++;
                    if ($snapshotHealth[$index]['trusted_for_absence_confirmation'] ?? false) {
                        $trustedMissingCaptureCount++;
                    }
                    $firstMissingIndex ??= $index;
                    $firstMissingSnapshot ??= $snapshot->key;
                }
                if ($trustedMissingCaptureCount === 2) {
                    $revisions[] = $this->revision(
                        $snapshot,
                        $previousSnapshot,
                        'presence_missing',
                        is_string($knownState['name'] ?? null) ? $knownState['name'] : null,
                        [],
                        $firstMissingSnapshot,
                    );
                }

                continue;
            }

            $name = $latestName;
            if (array_key_exists('name', $row)) {
                $name = is_string($row['name']) ? $row['name'] : null;
            }
            $rawRow = $rawRows[$index] ?? [];
            $groups = $everObserved
                ? $this->canonicalizer->diffGroups($knownState, $row, $knownRawState, $rawRow)
                : [];
            if ($groups !== []) {
                $groups = $this->annotateComparisonSnapshots($groups, $knownSnapshotByField);
            }

            if (! $everObserved) {
                $revisions[] = $this->revision($snapshot, $previousSnapshot, 'first_observed', $name, []);
                $firstObservedAt = $snapshot->observedAtIso();
            } else {
                if ($missingCaptureCount > 0 && $firstMissingIndex !== null) {
                    $absenceRanges[] = $this->absenceRange(
                        $snapshots,
                        $firstMissingIndex,
                        $index - 1,
                        $missingCaptureCount,
                        $trustedMissingCaptureCount,
                    );
                }

                if ($trustedMissingCaptureCount >= 2) {
                    $revisions[] = $this->revision(
                        $snapshot,
                        $previousSnapshot,
                        'presence_restored',
                        $name,
                        $groups,
                    );
                } elseif ($groups !== []) {
                    $revisions[] = $this->revision($snapshot, $previousSnapshot, 'changed', $name, $groups);
                }
            }

            $knownState = array_replace($knownState, $row);
            $knownRawState = array_replace($knownRawState, $rawRow);
            foreach (array_keys($row) as $field) {
                $knownSnapshotByField[$field] = $snapshot->key;
            }
            $everObserved = true;
            $missingCaptureCount = 0;
            $trustedMissingCaptureCount = 0;
            $firstMissingIndex = null;
            $firstMissingSnapshot = null;
            $lastObservedAt = $snapshot->observedAtIso();
            $latestName = $name;
            if (array_key_exists('spell_icon', $row)) {
                $latestIcon = is_int($row['spell_icon']) && $row['spell_icon'] >= 0
                    ? $row['spell_icon']
                    : null;
            }
        }

        if ($missingCaptureCount > 0 && $firstMissingIndex !== null) {
            $absenceRanges[] = $this->absenceRange(
                $snapshots,
                $firstMissingIndex,
                array_key_last($snapshots),
                $missingCaptureCount,
                $trustedMissingCaptureCount,
            );
        }

        return [
            'schema' => SpellHistoryArtifact::SCHEMA,
            'artifact_type' => 'spell',
            'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
            'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
            'dataset' => $datasetKey,
            'spell_id' => $spellId,
            'latest_name' => $latestName,
            'latest_icon' => $latestIcon,
            'first_observed_at' => $firstObservedAt,
            'last_observed_at' => $lastObservedAt,
            'present_in_latest_snapshot' => $rows[array_key_last($rows)] !== null,
            'latest_presence_status' => $rows[array_key_last($rows)] !== null
                ? 'present'
                : ($trustedMissingCaptureCount >= 2 ? 'not_observed' : 'uncertain_single_capture_gap'),
            'revision_count' => count($revisions),
            'absence_ranges' => $absenceRanges,
            'revisions' => $revisions,
        ];
    }

    /**
     * A schema may omit a field for one or more captures. Preserve the actual
     * capture that supplied each "before" value instead of implying that every
     * comparison came from the immediately preceding snapshot.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, string>  $knownSnapshotByField
     * @return list<array<string, mixed>>
     */
    private function annotateComparisonSnapshots(array $groups, array $knownSnapshotByField): array
    {
        foreach ($groups as &$group) {
            if (! is_array($group['changes'] ?? null)) {
                continue;
            }

            foreach ($group['changes'] as &$change) {
                $field = $change['field'] ?? null;
                if (is_string($field) && isset($knownSnapshotByField[$field])) {
                    $change['compared_from_snapshot'] = $knownSnapshotByField[$field];
                }
            }
            unset($change);
        }
        unset($group);

        return $groups;
    }

    /**
     * @param  list<SnapshotFile>  $snapshots
     * @return array<string, mixed>
     */
    private function absenceRange(
        array $snapshots,
        int $firstIndex,
        int $lastIndex,
        int $captures,
        int $trustedCaptures,
    ): array {
        return [
            'first_snapshot' => $snapshots[$firstIndex]->key,
            'last_snapshot' => $snapshots[$lastIndex]->key,
            'first_observed_at' => $snapshots[$firstIndex]->observedAtIso(),
            'last_observed_at' => $snapshots[$lastIndex]->observedAtIso(),
            'captures' => $captures,
            'trusted_captures' => $trustedCaptures,
            'confirmed' => $trustedCaptures >= 2,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    private function revision(
        SnapshotFile $snapshot,
        ?string $previousSnapshot,
        string $type,
        ?string $name,
        array $groups,
        ?string $firstMissingSnapshot = null,
    ): array {
        $revision = [
            'snapshot' => $snapshot->key,
            'observed_at' => $snapshot->observedAtIso(),
            'previous_snapshot' => $previousSnapshot,
            'type' => $type,
            'name' => $name,
            'groups' => $groups,
        ];
        if ($firstMissingSnapshot !== null) {
            $revision['first_missing_snapshot'] = $firstMissingSnapshot;
        }

        return $revision;
    }

    /** @param array<string, mixed> $artifact */
    private function writeSpellArtifact(string $stageDirectory, int $spellId, array $artifact): int
    {
        $path = $stageDirectory.'/'.SpellHistoryArtifact::spellRelativePath($spellId);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create spell artifact shard: {$directory}");
        }

        return $this->writeJsonFile($path, $artifact, SpellHistoryArtifact::MAX_SPELL_BYTES, "spell {$spellId}");
    }

    /** @param array<string, mixed> $manifest */
    private function writeCompletionMarker(string $stageDirectory, array $manifest, int $manifestBytes): void
    {
        $manifestHash = hash_file('sha256', $stageDirectory.'/manifest.json');
        if ($manifestHash === false) {
            throw new RuntimeException('Unable to hash the completed spell history manifest.');
        }
        $stats = $manifest['stats'];
        $marker = [
            'schema' => SpellHistoryArtifact::SCHEMA,
            'artifact_type' => 'completion',
            'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
            'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
            'dataset' => $manifest['dataset'],
            'manifest_sha256' => $manifestHash,
            'manifest_bytes' => $manifestBytes,
            'spell_count' => $stats['spell_count'],
            'revision_count' => $stats['revision_count'],
        ];
        $this->writeJsonFile(
            $stageDirectory.'/COMPLETE.json',
            $marker,
            SpellHistoryArtifact::MAX_COMPLETION_BYTES,
            'completion marker',
        );
    }

    /** @param array<string, mixed> $value */
    private function writeJsonFile(string $path, array $value, int $maximumBytes, string $label): int
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException("Unable to encode {$label} artifact.", 0, $exception);
        }

        $bytes = strlen($json);
        if ($bytes > $maximumBytes) {
            throw new RuntimeException("The {$label} artifact exceeds its {$maximumBytes}-byte safety limit.");
        }

        $written = file_put_contents($path, $json, LOCK_EX);
        if ($written !== $bytes) {
            throw new RuntimeException("Unable to write the complete {$label} artifact.");
        }

        return $bytes;
    }

    /** @param array<string, mixed> $value */
    private function encodedJsonLength(array $value, int $maximumBytes, string $label): int
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException("Unable to encode {$label} artifact.", 0, $exception);
        }

        $bytes = strlen($json);
        if ($bytes > $maximumBytes) {
            throw new RuntimeException("The {$label} artifact exceeds its {$maximumBytes}-byte safety limit.");
        }

        return $bytes;
    }

    /**
     * @param  list<SnapshotFile>  $snapshots
     * @param  list<array{sha256: string, bytes: int, encoding: string}>  $sourceMetadata
     */
    private function validateExistingDataset(
        string $directory,
        string $datasetsRoot,
        string $datasetKey,
        array $snapshots,
        array $sourceMetadata,
    ): void {
        if (is_link($directory)) {
            throw new RuntimeException('Existing spell history datasets cannot be symbolic links.');
        }
        SafePath::assertContained($directory, $datasetsRoot, 'Existing spell history dataset');
        $manifest = $this->decodeJsonFile(
            $directory.'/manifest.json',
            SpellHistoryArtifact::MAX_MANIFEST_BYTES,
            'existing spell history manifest',
        );
        $this->assertArtifactHeader($manifest, 'manifest', $datasetKey);

        $entries = $manifest['snapshots'] ?? null;
        $stats = $manifest['stats'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) !== count($snapshots)
            || ! is_array($stats)
            || ($stats['snapshot_count'] ?? null) !== count($snapshots)
            || ! is_int($stats['spell_count'] ?? null) || $stats['spell_count'] < 1
            || ! is_int($stats['revision_count'] ?? null) || $stats['revision_count'] < $stats['spell_count']) {
            throw new RuntimeException('Existing spell history manifest is incomplete.');
        }
        foreach ($snapshots as $index => $snapshot) {
            $entry = $entries[$index] ?? null;
            if (! is_array($entry)
                || ($entry['source_file'] ?? null) !== $snapshot->filename
                || ($entry['source_sha256'] ?? null) !== $sourceMetadata[$index]['sha256']
                || ($entry['source_bytes'] ?? null) !== $sourceMetadata[$index]['bytes']) {
                throw new RuntimeException('Existing spell history manifest does not match its source snapshots.');
            }
        }

        $completion = $this->decodeJsonFile(
            $directory.'/COMPLETE.json',
            SpellHistoryArtifact::MAX_COMPLETION_BYTES,
            'existing spell history completion marker',
        );
        $this->assertArtifactHeader($completion, 'completion', $datasetKey);
        $manifestHash = hash_file('sha256', $directory.'/manifest.json');
        $manifestBytes = filesize($directory.'/manifest.json');
        if ($manifestHash === false || $manifestBytes === false
            || ($completion['manifest_sha256'] ?? null) !== $manifestHash
            || ($completion['manifest_bytes'] ?? null) !== $manifestBytes
            || ($completion['spell_count'] ?? null) !== $stats['spell_count']
            || ($completion['revision_count'] ?? null) !== $stats['revision_count']) {
            throw new RuntimeException('Existing spell history completion marker is invalid.');
        }
    }

    /** @return array<string, mixed> */
    private function decodeJsonFile(string $path, int $maximumBytes, string $label): array
    {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException("Missing or unsafe {$label}.");
        }
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 2 || $bytes > $maximumBytes) {
            throw new RuntimeException("Invalid {$label} size.");
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Unable to read {$label}.");
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid JSON in {$label}.", 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid {$label} payload.");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $artifact */
    private function assertArtifactHeader(array $artifact, string $type, string $datasetKey): void
    {
        if (($artifact['schema'] ?? null) !== SpellHistoryArtifact::SCHEMA
            || ($artifact['artifact_type'] ?? null) !== $type
            || ($artifact['format_version'] ?? null) !== SpellHistoryArtifact::FORMAT_VERSION
            || ($artifact['canonical_format_version'] ?? null) !== SpellCanonicalizer::FORMAT_VERSION
            || ($artifact['dataset'] ?? null) !== $datasetKey) {
            throw new RuntimeException("Existing {$type} artifact has an incompatible format.");
        }
    }

    private function activate(string $outputRoot, string $datasetKey): void
    {
        if (preg_match(SpellHistoryArtifact::DATASET_KEY_PATTERN, $datasetKey) !== 1) {
            throw new RuntimeException('Refusing to activate an invalid dataset key.');
        }

        $lockPath = $outputRoot.'/.activation.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Spell history activation lock cannot be a symbolic link.');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock spell history activation.');
        }

        $temporary = $outputRoot.'/.CURRENT-'.bin2hex(random_bytes(8)).'.tmp';
        $current = $outputRoot.'/CURRENT';
        $backup = $outputRoot.'/'.self::ACTIVATION_BACKUP_FILENAME;

        try {
            $this->recoverInterruptedActivation($current, $backup);
            $pointer = $datasetKey."\n";
            $this->writeActivationPointer($temporary, $pointer);

            if (@rename($temporary, $current)) {
                return;
            }

            // Windows cannot rename over an existing file. The activation lock keeps
            // cooperating readers out while the old pointer is moved and replaced.
            if (! is_file($current) || ! rename($current, $backup)) {
                throw new RuntimeException('Unable to replace the spell history activation pointer.');
            }
            if (! rename($temporary, $current)) {
                if (! @rename($backup, $current)) {
                    throw new RuntimeException(
                        'Unable to install or restore the spell history activation pointer; the previous pointer remains recoverable in CURRENT.bak.'
                    );
                }
                throw new RuntimeException('Unable to install the spell history activation pointer.');
            }
            @unlink($backup);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function recoverInterruptedActivation(string $current, string $backup): void
    {
        if (is_link($current)) {
            throw new RuntimeException('Spell history CURRENT cannot be a symbolic link.');
        }
        if (is_link($backup)) {
            throw new RuntimeException('Spell history CURRENT backup cannot be a symbolic link.');
        }

        $hasCurrent = file_exists($current);
        $hasBackup = file_exists($backup);
        if ($hasCurrent && ! is_file($current)) {
            throw new RuntimeException('Spell history CURRENT must be a regular file.');
        }
        if ($hasBackup && ! is_file($backup)) {
            throw new RuntimeException('Spell history CURRENT backup must be a regular file.');
        }

        if ($hasCurrent) {
            $this->readActivationPointer($current, 'CURRENT');
            if ($hasBackup) {
                $this->readActivationPointer($backup, 'CURRENT backup');
                if (! unlink($backup)) {
                    throw new RuntimeException('Unable to remove the stale spell history CURRENT backup.');
                }
            }

            return;
        }
        if (! $hasBackup) {
            return;
        }

        $this->readActivationPointer($backup, 'CURRENT backup');
        if (! rename($backup, $current)) {
            throw new RuntimeException('Unable to recover the interrupted spell history activation pointer.');
        }
    }

    private function readActivationPointer(string $path, string $label): string
    {
        $bytes = filesize($path);
        $raw = file_get_contents($path);
        if ($bytes === false || $bytes < 64 || $bytes > 66 || $raw === false) {
            throw new RuntimeException("Spell history {$label} has an invalid size.");
        }

        $key = rtrim($raw, "\r\n");
        if (($raw !== $key && $raw !== $key."\n" && $raw !== $key."\r\n")
            || preg_match(SpellHistoryArtifact::DATASET_KEY_PATTERN, $key) !== 1) {
            throw new RuntimeException("Spell history {$label} contains an invalid dataset key.");
        }

        return $key;
    }

    private function writeActivationPointer(string $path, string $pointer): void
    {
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to create the spell history activation pointer.');
        }

        try {
            $offset = 0;
            $length = strlen($pointer);
            while ($offset < $length) {
                $written = fwrite($handle, substr($pointer, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to write the spell history activation pointer.');
                }
                $offset += $written;
            }
            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('Unable to flush the spell history activation pointer.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function removeStagingDirectory(string $stageDirectory, string $outputRoot): void
    {
        if (is_link($stageDirectory)) {
            throw new RuntimeException('Refusing to recurse into a symbolic-link staging directory.');
        }
        $normalizedStage = SafePath::assertContained($stageDirectory, $outputRoot, 'Spell history staging directory');
        $normalizedRoot = SafePath::normalize($outputRoot);
        if (dirname($normalizedStage) !== $normalizedRoot
            || ! str_starts_with(basename($normalizedStage), '.staging-')) {
            throw new RuntimeException('Refusing to remove an unexpected directory.');
        }

        $this->removeDirectoryTree($normalizedStage, $normalizedStage);
    }

    /**
     * Remove only a previously validated staging tree without relying on SPL
     * directory iterators, which can omit entries on Windows Docker bind mounts.
     */
    private function removeDirectoryTree(string $directory, string $stageRoot): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException("Unable to enumerate staging directory: {$directory}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path) || is_file($path)) {
                if (! unlink($path)) {
                    throw new RuntimeException("Unable to remove staged file: {$path}");
                }

                continue;
            }

            if (! is_dir($path)) {
                throw new RuntimeException("Refusing to remove an unexpected staged entry: {$path}");
            }

            $child = SafePath::assertContained($path, $stageRoot, 'Spell history staging child');
            $this->removeDirectoryTree($child, $stageRoot);
        }

        if (! rmdir($directory)) {
            throw new RuntimeException("Unable to remove staging directory: {$directory}");
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{dataset: string, snapshots: int, spells: int, revisions: int, bytes: int, reused: bool, path: string}
     */
    private function resultFromManifest(array $manifest, string $path, bool $reused): array
    {
        $stats = is_array($manifest['stats'] ?? null) ? $manifest['stats'] : [];

        return [
            'dataset' => (string) $manifest['dataset'],
            'snapshots' => (int) ($stats['snapshot_count'] ?? 0),
            'spells' => (int) ($stats['spell_count'] ?? 0),
            'revisions' => (int) ($stats['revision_count'] ?? 0),
            'bytes' => (int) ($stats['artifact_bytes'] ?? 0),
            'reused' => $reused,
            'path' => SafePath::normalize($path),
        ];
    }
}

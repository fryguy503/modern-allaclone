<?php

namespace App\Services\SpellHistory;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class SpellHistoryRepository
{
    private const MAX_PAGE_SIZE = 100;

    private const ACTIVATION_BACKUP_FILENAME = '.CURRENT.bak';

    private string $artifactRoot;

    private ?string $activeDatasetKey = null;

    private ?string $activeDatasetRoot = null;

    /** @var array<string, mixed>|null */
    private ?array $manifestCache = null;

    private int $stableReadDepth = 0;

    public function __construct(string $artifactRoot)
    {
        $this->artifactRoot = SafePath::prospectiveDirectory($artifactRoot, 'Spell history artifact root');
    }

    public function isAvailable(): bool
    {
        return $this->manifest() !== [];
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->stableRead(fn (): array => $this->readManifest());
    }

    /** @return array<string, mixed> */
    private function readManifest(): array
    {
        if (! $this->refreshActiveDataset()) {
            return [];
        }
        if ($this->manifestCache !== null) {
            return $this->manifestCache;
        }

        $path = $this->activeDatasetRoot.'/manifest.json';
        $manifest = $this->decodeJsonArtifact(
            $path,
            SpellHistoryArtifact::MAX_MANIFEST_BYTES,
            'spell history manifest',
        );
        $this->assertArtifactHeader($manifest, 'manifest');
        $this->validateManifest($manifest);
        $this->validateCompletionMarker($manifest, $path);

        return $this->manifestCache = $manifest;
    }

    /** @return array<string, mixed>|null */
    public function spell(int $spellId): ?array
    {
        return $this->stableRead(fn (): ?array => $this->readSpell($spellId));
    }

    /** @return array<string, mixed>|null */
    private function readSpell(int $spellId): ?array
    {
        $this->assertSpellId($spellId);
        if (! $this->refreshActiveDataset()) {
            return null;
        }
        $this->readManifest();

        $path = $this->activeDatasetRoot.'/'.SpellHistoryArtifact::spellRelativePath($spellId);
        if (is_link($path)) {
            throw new RuntimeException("The spell history for spell {$spellId} cannot be a symbolic link.");
        }
        if (! file_exists($path)) {
            return null;
        }

        $artifact = $this->decodeJsonArtifact(
            $path,
            SpellHistoryArtifact::MAX_SPELL_BYTES,
            "spell history for spell {$spellId}",
        );
        $this->assertArtifactHeader($artifact, 'spell');
        $this->validateSpellArtifact($artifact, $spellId);

        return $artifact;
    }

    public function hasHistory(int $spellId): bool
    {
        return $this->stableRead(fn (): bool => $this->readHasHistory($spellId));
    }

    private function readHasHistory(int $spellId): bool
    {
        $this->assertSpellId($spellId);
        if (! $this->refreshActiveDataset()) {
            return false;
        }
        $this->readManifest();

        $path = $this->activeDatasetRoot.'/'.SpellHistoryArtifact::spellRelativePath($spellId);
        if (is_link($path)) {
            throw new RuntimeException("The spell history for spell {$spellId} cannot be a symbolic link.");
        }
        if (! file_exists($path)) {
            return false;
        }

        $this->assertSafeArtifactFile($path, "spell history for spell {$spellId}");

        return true;
    }

    /**
     * Resolve a timezone-naive archive timestamp. A date-only value represents
     * the end of that calendar day; an explicit timestamp is exact to the second.
     *
     * @return array<string, mixed>|null
     */
    public function resolveSnapshotAtOrBefore(string $date): ?array
    {
        $target = $this->normalizeConfiguredDate($date);
        $snapshots = $this->manifest()['snapshots'] ?? [];
        if (! is_array($snapshots)) {
            return null;
        }

        $resolved = null;
        foreach ($snapshots as $snapshot) {
            if (! is_array($snapshot) || ! is_string($snapshot['observed_at'] ?? null)) {
                continue;
            }
            if ($snapshot['observed_at'] > $target) {
                break;
            }
            $resolved = $snapshot;
        }

        return $resolved;
    }

    /**
     * Controller-friendly, newest-first presentation of a spell timeline.
     *
     * @return array<string, mixed>|null
     */
    public function forSpell(int $spellId, int $page = 1, int $pageSize = 25): ?array
    {
        return $this->forSpellAtDate($spellId, $this->configuredBaselineDate(), $page, $pageSize);
    }

    /** @return array<string, mixed>|null */
    public function forSpellAtDate(int $spellId, ?string $baselineDate, int $page = 1, int $pageSize = 25): ?array
    {
        return $this->stableRead(
            fn (): ?array => $this->readForSpellAtDate($spellId, $baselineDate, $page, $pageSize),
        );
    }

    /** @return array<string, mixed>|null */
    private function readForSpellAtDate(int $spellId, ?string $baselineDate, int $page, int $pageSize): ?array
    {
        if ($page < 1) {
            throw new InvalidArgumentException('Spell history page must be at least 1.');
        }
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException('Spell history page size must be between 1 and '.self::MAX_PAGE_SIZE.'.');
        }

        $spell = $this->spell($spellId);
        if ($spell === null) {
            return null;
        }

        $manifest = $this->manifest();
        $baselineSnapshot = $baselineDate !== null && trim($baselineDate) !== ''
            ? $this->resolveSnapshotAtOrBefore($baselineDate)
            : null;
        $baseline = $this->baselineState($spell, $baselineDate, $baselineSnapshot, $pageSize);
        $newestRevisions = array_reverse($spell['revisions']);
        $total = count($newestRevisions);
        $lastPage = max(1, (int) ceil($total / $pageSize));
        if ($page > $lastPage) {
            return null;
        }
        $offset = ($page - 1) * $pageSize;
        $presented = array_map(
            fn (array $revision): array => $this->presentRevision($revision, $baseline['revision_snapshot'] ?? null),
            array_slice($newestRevisions, $offset, $pageSize),
        );
        $snapshots = is_array($manifest['snapshots'] ?? null) ? $manifest['snapshots'] : [];

        return [
            'spell' => [
                'id' => $spell['spell_id'],
                'name' => $spell['latest_name'],
                'icon' => $spell['latest_icon'] ?? null,
                'first_observed_at' => $spell['first_observed_at'],
                'last_observed_at' => $spell['last_observed_at'],
                'present_in_latest_snapshot' => $spell['present_in_latest_snapshot'],
                'latest_presence_status' => $spell['latest_presence_status'] ?? (
                    $spell['present_in_latest_snapshot'] ? 'present' : 'not_observed'
                ),
                'revision_count' => $spell['revision_count'],
            ],
            'revisions' => $presented,
            'pagination' => [
                'page' => $page,
                'current_page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'last_page' => $lastPage,
                'has_previous' => $page > 1,
                'has_more' => $page < $lastPage,
            ],
            'archive' => [
                'dataset' => $manifest['dataset'],
                'generated_at' => $manifest['generated_at'] ?? null,
                'snapshot_count' => count($snapshots),
                'first_snapshot' => $snapshots[0] ?? null,
                'last_snapshot' => $snapshots === [] ? null : $snapshots[array_key_last($snapshots)],
            ],
            'baseline' => $baseline,
        ];
    }

    private function refreshActiveDataset(): bool
    {
        if ($this->stableReadDepth > 0) {
            return $this->activeDatasetKey !== null && $this->activeDatasetRoot !== null;
        }

        if (! is_dir($this->artifactRoot)) {
            $this->clearActiveDataset();

            return false;
        }

        $root = SafePath::existingDirectory($this->artifactRoot, 'Spell history artifact root');
        $lock = $this->sharedActivationLock($root);
        try {
            $pointerPath = $root.'/CURRENT';
            if (is_link($pointerPath)) {
                throw new RuntimeException('Spell history CURRENT must be a regular file.');
            }
            if (file_exists($pointerPath)) {
                if (! is_file($pointerPath)) {
                    throw new RuntimeException('Spell history CURRENT must be a regular file.');
                }
                $key = $this->readActivationPointer($pointerPath, 'CURRENT', $root);
            } else {
                $pointerPath = $root.'/'.self::ACTIVATION_BACKUP_FILENAME;
                if (! file_exists($pointerPath) && ! is_link($pointerPath)) {
                    $this->clearActiveDataset();

                    return false;
                }
                if (is_link($pointerPath) || ! is_file($pointerPath)) {
                    throw new RuntimeException('Spell history .CURRENT.bak must be a regular file.');
                }
                $key = $this->readActivationPointer($pointerPath, self::ACTIVATION_BACKUP_FILENAME, $root);
            }

            if ($key === $this->activeDatasetKey && $this->activeDatasetRoot !== null) {
                return true;
            }

            $datasetsRoot = SafePath::existingDirectory($root.'/datasets', 'Spell history datasets directory');
            $datasetPath = $datasetsRoot.'/'.$key;
            if (is_link($datasetPath) || ! is_dir($datasetPath)) {
                throw new RuntimeException('The active spell history dataset is missing or unsafe.');
            }

            $datasetRoot = SafePath::assertContained($datasetPath, $datasetsRoot, 'Active spell history dataset');
            $this->activeDatasetKey = $key;
            $this->activeDatasetRoot = $datasetRoot;
            $this->manifestCache = null;

            return true;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function readActivationPointer(string $path, string $label, string $root): string
    {
        $resolved = SafePath::assertContained($path, $root, "Spell history {$label}");
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

        return $key;
    }

    private function stableRead(callable $read): mixed
    {
        if ($this->stableReadDepth === 0) {
            $this->refreshActiveDataset();
        }

        $this->stableReadDepth++;
        try {
            return $read();
        } finally {
            $this->stableReadDepth--;
        }
    }

    /** @return resource|null */
    private function sharedActivationLock(string $root)
    {
        $lockPath = $root.'/.activation.lock';
        if (! file_exists($lockPath)) {
            return null;
        }
        if (is_link($lockPath) || ! is_file($lockPath)) {
            throw new RuntimeException('Spell history activation lock is unsafe.');
        }

        $lock = fopen($lockPath, 'rb');
        if ($lock === false || ! flock($lock, LOCK_SH)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to acquire the spell history activation lock.');
        }

        return $lock;
    }

    /** @return array<string, mixed> */
    private function decodeJsonArtifact(string $path, int $maximumBytes, string $label): array
    {
        $resolved = $this->assertSafeArtifactFile($path, $label);
        $bytes = filesize($resolved);
        if ($bytes === false || $bytes < 2 || $bytes > $maximumBytes) {
            throw new RuntimeException("The {$label} has an invalid size.");
        }
        $json = file_get_contents($resolved);
        if ($json === false) {
            throw new RuntimeException("Unable to read the {$label}.");
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The {$label} is not valid JSON.", 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException("The {$label} must contain a JSON object.");
        }

        return $decoded;
    }

    private function assertSafeArtifactFile(string $path, string $label): string
    {
        if ($this->activeDatasetRoot === null || is_link($path) || ! is_file($path)) {
            throw new RuntimeException("The {$label} is missing or unsafe.");
        }

        return SafePath::assertContained($path, $this->activeDatasetRoot, ucfirst($label));
    }

    /** @param array<string, mixed> $artifact */
    private function assertArtifactHeader(array $artifact, string $type): void
    {
        if (($artifact['schema'] ?? null) !== SpellHistoryArtifact::SCHEMA
            || ($artifact['artifact_type'] ?? null) !== $type
            || ($artifact['format_version'] ?? null) !== SpellHistoryArtifact::FORMAT_VERSION
            || ($artifact['canonical_format_version'] ?? null) !== SpellCanonicalizer::FORMAT_VERSION
            || ($artifact['dataset'] ?? null) !== $this->activeDatasetKey) {
            throw new RuntimeException("The active spell history {$type} has an incompatible format.");
        }
    }

    /** @param array<string, mixed> $manifest */
    private function validateManifest(array $manifest): void
    {
        $snapshots = $manifest['snapshots'] ?? null;
        $stats = $manifest['stats'] ?? null;
        if (! is_array($snapshots) || ! array_is_list($snapshots) || ! is_array($stats)) {
            throw new RuntimeException('The spell history manifest is missing snapshots or stats.');
        }

        $previousTimestamp = null;
        foreach ($snapshots as $index => $snapshot) {
            $expectedPrevious = $index > 0 ? $snapshots[$index - 1]['key'] : null;
            if (! is_array($snapshot)
                || ($snapshot['sequence'] ?? null) !== $index + 1
                || ! is_string($snapshot['key'] ?? null)
                || preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $snapshot['key']) !== 1
                || ! is_string($snapshot['observed_at'] ?? null)
                || ! $this->validNaiveTimestamp($snapshot['observed_at'])
                || ! is_int($snapshot['source_physical_line_count'] ?? null)
                || $snapshot['source_physical_line_count'] < 1
                || ! in_array($snapshot['health_status'] ?? null, ['healthy', 'anomalously_low_record_count'], true)
                || ! is_bool($snapshot['trusted_for_absence_confirmation'] ?? null)
                || ($snapshot['previous_snapshot'] ?? null) !== $expectedPrevious) {
                throw new RuntimeException('The spell history manifest contains an invalid snapshot entry.');
            }
            if ($previousTimestamp !== null && $snapshot['observed_at'] <= $previousTimestamp) {
                throw new RuntimeException('Spell history snapshots must have unique chronological timestamps.');
            }
            $previousTimestamp = $snapshot['observed_at'];
        }

        if (($stats['snapshot_count'] ?? null) !== count($snapshots)
            || ! is_int($stats['spell_count'] ?? null) || $stats['spell_count'] < 1
            || ! is_int($stats['revision_count'] ?? null) || $stats['revision_count'] < $stats['spell_count']) {
            throw new RuntimeException('The spell history manifest stats are inconsistent.');
        }
    }

    /** @param array<string, mixed> $manifest */
    private function validateCompletionMarker(array $manifest, string $manifestPath): void
    {
        if ($this->activeDatasetRoot === null) {
            throw new RuntimeException('The active spell history dataset is unavailable.');
        }

        $completion = $this->decodeJsonArtifact(
            $this->activeDatasetRoot.'/COMPLETE.json',
            SpellHistoryArtifact::MAX_COMPLETION_BYTES,
            'spell history completion marker',
        );
        $this->assertArtifactHeader($completion, 'completion');

        $manifestHash = hash_file('sha256', $manifestPath);
        $manifestBytes = filesize($manifestPath);
        $expectedHash = $completion['manifest_sha256'] ?? null;
        $stats = $manifest['stats'];
        if ($manifestHash === false || $manifestBytes === false
            || ! is_string($expectedHash)
            || preg_match(SpellHistoryArtifact::DATASET_KEY_PATTERN, $expectedHash) !== 1
            || ! hash_equals($manifestHash, $expectedHash)
            || ($completion['manifest_bytes'] ?? null) !== $manifestBytes
            || ($completion['spell_count'] ?? null) !== $stats['spell_count']
            || ($completion['revision_count'] ?? null) !== $stats['revision_count']) {
            throw new RuntimeException('The active spell history completion marker is invalid.');
        }
    }

    /** @param array<string, mixed> $artifact */
    private function validateSpellArtifact(array $artifact, int $spellId): void
    {
        $revisions = $artifact['revisions'] ?? null;
        $absenceRanges = $artifact['absence_ranges'] ?? [];
        $latestIcon = $artifact['latest_icon'] ?? null;
        $latestPresenceStatus = $artifact['latest_presence_status'] ?? null;
        if (($artifact['spell_id'] ?? null) !== $spellId
            || ! is_array($revisions)
            || ! array_is_list($revisions)
            || $revisions === []
            || ! is_array($absenceRanges)
            || ! array_is_list($absenceRanges)
            || ($artifact['revision_count'] ?? null) !== count($revisions)
            || ! is_string($artifact['first_observed_at'] ?? null)
            || ! $this->validNaiveTimestamp($artifact['first_observed_at'])
            || ! is_string($artifact['last_observed_at'] ?? null)
            || ! $this->validNaiveTimestamp($artifact['last_observed_at'])
            || $artifact['last_observed_at'] < $artifact['first_observed_at']
            || ! is_bool($artifact['present_in_latest_snapshot'] ?? null)
            || ($latestPresenceStatus !== null
                && ! in_array($latestPresenceStatus, ['present', 'not_observed', 'uncertain_single_capture_gap'], true))
            || ($latestPresenceStatus === 'present' && ! $artifact['present_in_latest_snapshot'])
            || ($latestPresenceStatus !== null && $latestPresenceStatus !== 'present' && $artifact['present_in_latest_snapshot'])
            || (! is_null($artifact['latest_name'] ?? null) && ! is_string($artifact['latest_name']))
            || ($latestIcon !== null && (! is_int($latestIcon) || $latestIcon < 0))) {
            throw new RuntimeException("The spell history artifact for {$spellId} is inconsistent.");
        }

        $snapshotEntries = $this->manifest()['snapshots'] ?? [];
        $snapshotIndexes = [];
        foreach ($snapshotEntries as $index => $entry) {
            $snapshotIndexes[$entry['key']] = $index;
        }

        $previousTimestamp = null;
        foreach ($revisions as $revisionIndex => $revision) {
            if (! is_array($revision)
                || ! in_array($revision['type'] ?? null, ['first_observed', 'changed', 'presence_missing', 'presence_restored'], true)
                || ! is_string($revision['snapshot'] ?? null)
                || ! array_key_exists($revision['snapshot'], $snapshotIndexes)
                || ! is_string($revision['observed_at'] ?? null)
                || ! $this->validNaiveTimestamp($revision['observed_at'])
                || $snapshotEntries[$snapshotIndexes[$revision['snapshot']]]['observed_at'] !== $revision['observed_at']
                || ! is_array($revision['groups'] ?? null)
                || ! array_is_list($revision['groups'])) {
                throw new RuntimeException("The spell history artifact for {$spellId} has an invalid revision.");
            }
            if (($revisionIndex === 0) !== ($revision['type'] === 'first_observed')) {
                throw new RuntimeException("The spell history artifact for {$spellId} has an invalid first revision.");
            }
            $snapshotIndex = $snapshotIndexes[$revision['snapshot']];
            $expectedPrevious = $snapshotIndex > 0 ? $snapshotEntries[$snapshotIndex - 1]['key'] : null;
            if (($revision['previous_snapshot'] ?? null) !== $expectedPrevious) {
                throw new RuntimeException("The spell history artifact for {$spellId} has an invalid revision interval.");
            }
            foreach ($revision['groups'] as $group) {
                $this->validateGroup($group, $spellId);
            }
            if ($previousTimestamp !== null && $revision['observed_at'] < $previousTimestamp) {
                throw new RuntimeException("The spell history revisions for {$spellId} are not chronological.");
            }
            $previousTimestamp = $revision['observed_at'];
        }
        if ($revisions[0]['observed_at'] !== $artifact['first_observed_at']) {
            throw new RuntimeException("The spell history artifact for {$spellId} has an inconsistent first observation.");
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
                throw new RuntimeException("The spell history artifact for {$spellId} has an invalid absence range.");
            }
            $firstIndex = $snapshotIndexes[$firstKey];
            $lastIndex = $snapshotIndexes[$lastKey];
            $expectedTrustedCaptures = count(array_filter(
                array_slice($snapshotEntries, $firstIndex, $lastIndex - $firstIndex + 1),
                static fn (array $entry): bool => $entry['trusted_for_absence_confirmation'],
            ));
            if ($firstIndex <= $previousRangeEnd || $lastIndex < $firstIndex
                || $captures !== $lastIndex - $firstIndex + 1
                || $trustedCaptures !== $expectedTrustedCaptures
                || ($range['first_observed_at'] ?? null) !== $snapshotEntries[$firstIndex]['observed_at']
                || ($range['last_observed_at'] ?? null) !== $snapshotEntries[$lastIndex]['observed_at']) {
                throw new RuntimeException("The spell history artifact for {$spellId} has an inconsistent absence range.");
            }
            $previousRangeEnd = $lastIndex;
        }
    }

    private function validateGroup(mixed $group, int $spellId): void
    {
        if (! is_array($group)
            || ! is_string($group['key'] ?? null)
            || ! is_string($group['section'] ?? null)
            || ! is_string($group['label'] ?? null)
            || ! is_array($group['changes'] ?? null)
            || ! array_is_list($group['changes'])) {
            throw new RuntimeException("The spell history artifact for {$spellId} has an invalid change group.");
        }
        foreach ($group['changes'] as $change) {
            if (! is_array($change)
                || ! is_string($change['field'] ?? null)
                || ! is_string($change['label'] ?? null)
                || ! array_key_exists('old', $change)
                || ! array_key_exists('new', $change)) {
                throw new RuntimeException("The spell history artifact for {$spellId} has an invalid change.");
            }
        }
    }

    private function normalizeConfiguredDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Spell history baseline date is invalid.');
            }

            return $date.'T23:59:59';
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})$/D', $date, $matches) === 1) {
            $normalized = $matches[1].'T'.$matches[2];
            if (! $this->validNaiveTimestamp($normalized)) {
                throw new InvalidArgumentException('Spell history baseline timestamp is invalid.');
            }

            return $normalized;
        }

        throw new InvalidArgumentException('Spell history baseline must be YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS.');
    }

    private function validNaiveTimestamp(string $timestamp): bool
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s',
            $timestamp,
            new DateTimeZone('UTC'),
        );

        return $parsed !== false && $parsed->format('Y-m-d\TH:i:s') === $timestamp;
    }

    /**
     * @param  array<string, mixed>  $spell
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    private function baselineState(array $spell, ?string $configuredDate, ?array $snapshot, int $pageSize): ?array
    {
        if ($configuredDate === null || trim($configuredDate) === '') {
            return null;
        }

        $revisions = $spell['revisions'];
        $revisionSnapshot = null;
        $effectiveRevisionIndex = null;
        $present = null;
        $presenceStatus = 'no_snapshot';
        if ($snapshot !== null) {
            foreach ($revisions as $index => $revision) {
                if ($revision['observed_at'] > $snapshot['observed_at']) {
                    break;
                }
                $revisionSnapshot = $revision['snapshot'];
                $effectiveRevisionIndex = $index;
            }

            $present = true;
            $presenceStatus = 'present';
            if (($spell['first_observed_at'] ?? null) > $snapshot['observed_at']) {
                $present = false;
                $presenceStatus = 'not_yet_observed';
            } else {
                foreach (($spell['absence_ranges'] ?? []) as $range) {
                    if ($snapshot['observed_at'] < $range['first_observed_at']
                        || $snapshot['observed_at'] > $range['last_observed_at']) {
                        continue;
                    }

                    $present = $range['confirmed'] ? false : null;
                    $presenceStatus = $range['confirmed'] ? 'not_observed' : 'uncertain_single_capture_gap';
                    break;
                }
            }
        }

        $revisionPage = $effectiveRevisionIndex === null
            ? 0
            : (int) ceil((count($revisions) - $effectiveRevisionIndex) / $pageSize);
        $lastKnownRevisionSnapshot = $revisionSnapshot;
        $lastKnownPage = $revisionPage;
        if ($presenceStatus !== 'present') {
            $revisionSnapshot = null;
            $revisionPage = 0;
        }

        return [
            'configured_date' => $configuredDate,
            'configured_captured_at' => $configuredDate,
            'snapshot' => $snapshot,
            'matched_captured_at' => $snapshot['observed_at'] ?? null,
            'revision_snapshot' => $revisionSnapshot,
            'page' => $revisionPage,
            'last_known_revision_snapshot' => $lastKnownRevisionSnapshot,
            'last_known_page' => $lastKnownPage,
            'spell_present' => $present,
            'presence_status' => $presenceStatus,
        ];
    }

    /** @param array<string, mixed> $revision */
    private function presentRevision(array $revision, ?string $baselineRevisionSnapshot): array
    {
        $availability = match ($revision['type']) {
            'first_observed' => ['Not yet observed', 'Available'],
            'presence_missing' => ['Available', 'Not observed'],
            'presence_restored' => ['Not observed', 'Available'],
            default => null,
        };
        if ($availability !== null) {
            array_unshift(
                $revision['groups'],
                [
                    'key' => 'availability',
                    'section' => 'availability',
                    'label' => 'Availability',
                    'changes' => [[
                        'field' => 'availability',
                        'label' => 'Availability',
                        'old' => $availability[0],
                        'new' => $availability[1],
                    ]],
                ],
            );
        }

        $changes = [];
        foreach ($revision['groups'] as &$group) {
            $section = $group['section'] === 'advanced' ? 'technical' : $group['section'];
            $group['section'] = $section;
            foreach ($group['changes'] as $change) {
                $label = in_array($section, ['effects', 'reagents'], true)
                    ? $group['label'].' — '.$change['label']
                    : $change['label'];
                $presented = [
                    ...$change,
                    'label' => $label,
                    'category' => $section,
                    'before' => $change['old'],
                    'after' => $change['new'],
                ];
                $beforeDisplay = $this->displayValue($change['field'], $change['old']);
                $afterDisplay = $this->displayValue($change['field'], $change['new']);
                if ($beforeDisplay !== null) {
                    $presented['before_display'] = $beforeDisplay;
                }
                if ($afterDisplay !== null) {
                    $presented['after_display'] = $afterDisplay;
                }
                $changes[] = $presented;
            }
        }
        unset($group);

        $revision['is_server_baseline'] = $baselineRevisionSnapshot !== null
            && $revision['snapshot'] === $baselineRevisionSnapshot;
        $revision['changes'] = $changes;
        $revision['change_count'] = count($changes);

        return $revision;
    }

    private function displayValue(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return 'Not set';
        }
        if (preg_match('/^effects\.\d+\.attribute$/D', $field) !== 1
            || (! is_int($value) && (! is_string($value) || preg_match('/^\d+$/D', $value) !== 1))) {
            return null;
        }

        $effectId = (int) $value;
        if (! function_exists('app') || ! app()->bound('config')) {
            return (string) $effectId;
        }
        $effectName = config("everquest.spell_effects.{$effectId}");

        return is_string($effectName) && trim($effectName) !== ''
            ? trim($effectName)." ({$effectId})"
            : (string) $effectId;
    }

    private function configuredBaselineDate(): ?string
    {
        if (! function_exists('app') || ! app()->bound('config')) {
            return null;
        }

        $value = config('everquest.spell_history.baseline_date');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function assertSpellId(int $spellId): void
    {
        if ($spellId < 0) {
            throw new InvalidArgumentException('Spell history ids must be non-negative.');
        }
    }

    private function clearActiveDataset(): void
    {
        $this->activeDatasetKey = null;
        $this->activeDatasetRoot = null;
        $this->manifestCache = null;
    }
}

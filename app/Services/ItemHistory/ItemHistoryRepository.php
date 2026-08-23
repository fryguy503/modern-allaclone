<?php

namespace App\Services\ItemHistory;

use App\Services\SpellHistory\SafePath;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class ItemHistoryRepository
{
    private const MAX_PAGE_SIZE = 100;

    private const MAX_REVISIONS = 5_000;

    private const MAX_CHANGES_PER_REVISION = 512;

    private const MAX_TOTAL_CHANGES = 20_000;

    private const MAX_SNAPSHOT_LINES_PER_REVISION = 1_024;

    private const MAX_TOTAL_SNAPSHOT_LINES = 50_000;

    private const MAX_ICON_IDS_PER_CHANGE = 32;

    private const MAX_DETAIL_METADATA_FIELDS = 128;

    private const MAX_DETAIL_LINKS = 256;

    private const MAX_JSON_STRUCTURAL_MARKERS = 100_000;

    private const MAX_JSON_VALUES = 200_000;

    private const MAX_JSON_CONTAINER_ENTRIES = 20_000;

    private const MAX_JSON_DEPTH = 16;

    private const MAX_JSON_STRING_BYTES = 65_536;

    private const MAX_JSON_TOTAL_STRING_BYTES = 16_777_216;

    private const MAX_NAME_BYTES = 512;

    private const MAX_FIELD_BYTES = 256;

    private const MAX_VALUE_BYTES = 16_384;

    private const MAX_DISPLAY_BYTES = 16_384;

    private const MAX_SNAPSHOT_LINE_BYTES = 16_384;

    private const MAX_METADATA_VALUE_BYTES = 4_096;

    private const MAX_LINK_TEXT_BYTES = 4_096;

    private const MAX_LINK_HREF_BYTES = 8_192;

    private const MAX_ARTIFACT_ESTIMATED_RENDER_BYTES = 67_108_864;

    private const MAX_PAGE_ESTIMATED_RENDER_BYTES = 2_097_152;

    private const PAGE_RENDER_OVERHEAD_BYTES = 65_536;

    private const REVISION_RENDER_OVERHEAD_BYTES = 4_096;

    private const CHANGE_RENDER_OVERHEAD_BYTES = 2_048;

    private const SNAPSHOT_LINE_RENDER_OVERHEAD_BYTES = 128;

    private const ICON_RENDER_OVERHEAD_BYTES = 512;

    private const HTML_ESCAPE_EXPANSION = 6;

    private const SOURCE_VALUES = ['Live', 'Test'];

    private const REVISION_TYPES = ['initial', 'changed'];

    private const CHANGE_OPERATIONS = ['added', 'removed', 'changed', 'initial', 'unknown'];

    private const OBSERVED_PRECISIONS = ['minute', 'second', 'unknown'];

    private string $artifactRoot;

    public function __construct(string $artifactRoot)
    {
        $this->artifactRoot = SafePath::prospectiveDirectory($artifactRoot, 'Item history artifact root');
    }

    public function hasHistory(int $itemId): bool
    {
        return $this->item($itemId) !== null;
    }

    /** @return array<string, mixed>|null */
    public function item(int $itemId): ?array
    {
        $this->assertItemId($itemId);

        if (! is_dir($this->artifactRoot)) {
            return null;
        }

        $root = SafePath::existingDirectory($this->artifactRoot, 'Item history artifact root');
        $primaryPath = $root.'/'.ItemHistoryArtifact::itemRelativePath($itemId);

        if (is_link($primaryPath)) {
            throw new RuntimeException("The item history for item {$itemId} cannot be a symbolic link.");
        }
        $path = $primaryPath;
        $label = "Item history for item {$itemId}";
        if (! file_exists($primaryPath)) {
            $backupPath = $primaryPath.'.bak';
            if (is_link($backupPath)) {
                throw new RuntimeException("The item history backup for item {$itemId} cannot be a symbolic link.");
            }
            if (! file_exists($backupPath)) {
                return null;
            }

            $path = $backupPath;
            $label = "Item history backup for item {$itemId}";
        }

        $path = SafePath::assertContained($path, $root, $label);
        if (! is_file($path)) {
            throw new RuntimeException("{$label} must be a regular file.");
        }

        $artifact = $this->decodeLockedJson($path, $itemId);
        $this->validateArtifact($artifact, $itemId);

        return $this->normalizeArtifact($artifact);
    }

    /** @return array<string, mixed>|null */
    public function forItem(int $itemId, int $page = 1, int $pageSize = 25): ?array
    {
        if ($page < 1) {
            throw new InvalidArgumentException('Item history page must be at least 1.');
        }
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException(
                'Item history page size must be between 1 and '.self::MAX_PAGE_SIZE.'.',
            );
        }

        $item = $this->item($itemId);
        if ($item === null) {
            return null;
        }

        $revisions = array_reverse($item['revisions']);
        $total = count($revisions);
        $pageSlices = $this->pageSlices($revisions, $pageSize, $item['latest_name']);
        $lastPage = count($pageSlices);
        if ($page > $lastPage) {
            return null;
        }

        $pageSlice = $pageSlices[$page - 1];

        return [
            'item' => [
                'id' => $item['item_id'],
                'name' => $item['latest_name'],
                'icon' => $item['latest_icon'],
                'first_observed_at' => $item['first_observed_at'],
                'last_observed_at' => $item['last_observed_at'],
                'revision_count' => $item['revision_count'],
            ],
            'revisions' => array_slice($revisions, $pageSlice['offset'], $pageSlice['length']),
            'pagination' => [
                'current_page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'last_page' => $lastPage,
                'has_previous' => $page > 1,
                'has_more' => $page < $lastPage,
            ],
            'archive' => [
                'generated_at' => $item['generated_at'],
                'sources' => $item['sources'],
                'complete' => $item['complete'],
                'gaps' => $item['gaps'],
                'revision_count' => $item['revision_count'],
                'first_observed_at' => $item['first_observed_at'],
                'last_observed_at' => $item['last_observed_at'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function decodeLockedJson(string $path, int $itemId): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open item history for item {$itemId}.");
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                throw new RuntimeException("Unable to lock item history for item {$itemId}.");
            }

            $stat = fstat($handle);
            $bytes = is_array($stat) ? ($stat['size'] ?? null) : null;
            if (! is_int($bytes) || $bytes < 2 || $bytes > ItemHistoryArtifact::MAX_ITEM_BYTES) {
                throw new RuntimeException("The item history for item {$itemId} has an invalid size.");
            }

            $json = stream_get_contents($handle);
            if (! is_string($json) || strlen($json) !== $bytes) {
                throw new RuntimeException("Unable to read the complete item history for item {$itemId}.");
            }

            $structuralMarkers = substr_count($json, '{')
                + substr_count($json, '[')
                + substr_count($json, ',');
            if ($structuralMarkers > self::MAX_JSON_STRUCTURAL_MARKERS) {
                throw new RuntimeException("The item history for item {$itemId} is too structurally complex.");
            }
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The item history for item {$itemId} is not valid JSON.", 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("The item history for item {$itemId} must be a JSON object.");
        }

        $valueCount = 0;
        $stringBytes = 0;
        $this->validateDecodedComplexity($decoded, $itemId, 0, $valueCount, $stringBytes);

        return $decoded;
    }

    /** @param array<string, mixed> $artifact */
    private function validateArtifact(array $artifact, int $itemId): void
    {
        $revisions = $artifact['revisions'] ?? null;
        $sources = $artifact['sources'] ?? null;
        $gaps = $artifact['gaps'] ?? null;

        if (($artifact['schema'] ?? null) !== ItemHistoryArtifact::SCHEMA
            || ($artifact['artifact_type'] ?? null) !== 'item'
            || ($artifact['format_version'] ?? null) !== ItemHistoryArtifact::FORMAT_VERSION
            || ($artifact['item_id'] ?? null) !== $itemId
            || ! $this->validGeneratedAt($artifact['generated_at'] ?? null)
            || ! array_key_exists('latest_name', $artifact)
            || (! is_null($artifact['latest_name'])
                && ! $this->boundedString($artifact['latest_name'], self::MAX_NAME_BYTES))
            || ! array_key_exists('latest_icon', $artifact)
            || (! is_null($artifact['latest_icon'])
                && (! is_int($artifact['latest_icon']) || $artifact['latest_icon'] < 0))
            || ! array_key_exists('first_observed_at', $artifact)
            || ! $this->nullableTimestamp($artifact['first_observed_at'] ?? null)
            || ! array_key_exists('last_observed_at', $artifact)
            || ! $this->nullableTimestamp($artifact['last_observed_at'] ?? null)
            || ! is_array($sources)
            || ! array_is_list($sources)
            || $sources === []
            || count($sources) > count(self::SOURCE_VALUES)
            || ! is_array($gaps)
            || ! array_is_list($gaps)
            || ($artifact['complete'] ?? null) !== true
            || ! is_array($revisions)
            || ! array_is_list($revisions)
            || $revisions === []
            || count($revisions) > self::MAX_REVISIONS
            || ($artifact['revision_count'] ?? null) !== count($revisions)) {
            throw new RuntimeException("The item history artifact for {$itemId} is inconsistent.");
        }

        if ($artifact['first_observed_at'] !== null
            && $artifact['last_observed_at'] !== null
            && $artifact['last_observed_at'] < $artifact['first_observed_at']) {
            throw new RuntimeException("The item history artifact for {$itemId} has invalid observation bounds.");
        }
        if ($gaps !== []) {
            throw new RuntimeException("The complete item history artifact for {$itemId} cannot contain coverage gaps.");
        }

        $seenSources = [];
        foreach ($sources as $source) {
            if (! is_string($source)
                || ! in_array($source, self::SOURCE_VALUES, true)
                || isset($seenSources[$source])) {
                throw new RuntimeException("The item history artifact for {$itemId} has invalid sources.");
            }
            $seenSources[$source] = true;
        }

        $previousTimestamp = null;
        $observedTimestamps = [];
        $seenEntryIds = [];
        $totalChanges = 0;
        $totalSnapshotLines = 0;
        $estimatedRenderBytes = self::PAGE_RENDER_OVERHEAD_BYTES
            + $this->escapedRenderBytes($artifact['latest_name']);
        foreach ($revisions as $revision) {
            if (! is_array($revision)) {
                throw new RuntimeException("The item history artifact for {$itemId} has an invalid revision.");
            }

            $entryId = $revision['entry_id'] ?? null;
            $source = $revision['source'] ?? null;
            $timestamp = $revision['observed_at'] ?? null;
            $precision = $revision['observed_precision'] ?? ($revision['observed_at_precision'] ?? 'unknown');
            $type = $revision['type'] ?? null;
            $changes = $revision['changes'] ?? null;

            if (! is_int($entryId) || $entryId < 1 || isset($seenEntryIds[$entryId])
                || ! is_string($source) || ! in_array($source, self::SOURCE_VALUES, true)
                || ! isset($seenSources[$source])
                || ! array_key_exists('observed_at', $revision)
                || ! is_string($timestamp)
                || ! $this->nullableTimestamp($timestamp)
                || ! is_string($precision) || ! in_array($precision, self::OBSERVED_PRECISIONS, true)
                || ! is_string($type) || ! in_array($type, self::REVISION_TYPES, true)
                || ! is_array($changes) || ! array_is_list($changes)
                || $changes === []
                || count($changes) > self::MAX_CHANGES_PER_REVISION) {
                throw new RuntimeException("The item history artifact for {$itemId} has an invalid revision.");
            }

            $totalChanges += count($changes);
            if ($totalChanges > self::MAX_TOTAL_CHANGES) {
                throw new RuntimeException("The item history artifact for {$itemId} contains too many changes.");
            }

            if (is_string($timestamp) && $previousTimestamp !== null && $timestamp < $previousTimestamp) {
                throw new RuntimeException("The item history revisions for {$itemId} are not chronological.");
            }
            if (is_string($timestamp)) {
                $previousTimestamp = $timestamp;
                $observedTimestamps[] = $timestamp;
            }

            foreach ($changes as $change) {
                $this->validateChange($change, $itemId);
            }
            $snapshotLineCount = $this->validateRevisionDetail($revision['detail'] ?? null, $itemId);
            $totalSnapshotLines += $snapshotLineCount;
            if ($totalSnapshotLines > self::MAX_TOTAL_SNAPSHOT_LINES) {
                throw new RuntimeException("The item history artifact for {$itemId} contains too many snapshot lines.");
            }

            foreach (['detail_observed_at', 'detail_verified_at'] as $timestampKey) {
                if (array_key_exists($timestampKey, $revision)
                    && ! $this->nullableTimestamp($revision[$timestampKey])) {
                    throw new RuntimeException("The item history artifact for {$itemId} has invalid revision detail timestamps.");
                }
            }

            $captureHash = $revision['capture_sha256'] ?? data_get($revision, 'capture.sha256');
            if (! is_string($captureHash) || preg_match('/^[a-f0-9]{64}$/D', $captureHash) !== 1) {
                throw new RuntimeException("The item history artifact for {$itemId} has an invalid capture hash.");
            }

            $revisionRenderBytes = $this->estimateRevisionRenderBytes($revision);
            if ($revisionRenderBytes + self::PAGE_RENDER_OVERHEAD_BYTES
                > self::MAX_PAGE_ESTIMATED_RENDER_BYTES) {
                throw new RuntimeException("The item history revision for {$itemId} is too large to render safely.");
            }
            $estimatedRenderBytes += $revisionRenderBytes;
            if ($estimatedRenderBytes > self::MAX_ARTIFACT_ESTIMATED_RENDER_BYTES) {
                throw new RuntimeException("The item history artifact for {$itemId} is too large to render safely.");
            }

            $seenEntryIds[$entryId] = true;
        }

        $expectedFirst = $observedTimestamps[0] ?? null;
        $expectedLast = $observedTimestamps === [] ? null : $observedTimestamps[array_key_last($observedTimestamps)];
        if ($artifact['first_observed_at'] !== $expectedFirst
            || $artifact['last_observed_at'] !== $expectedLast) {
            throw new RuntimeException("The item history artifact for {$itemId} has inconsistent observation bounds.");
        }

    }

    private function validateChange(mixed $change, int $itemId): void
    {
        if (! is_array($change)) {
            throw new RuntimeException("The item history artifact for {$itemId} has an invalid change.");
        }

        $operation = $change['operation'] ?? 'unknown';
        $field = $change['field'] ?? null;
        $before = $change['before'] ?? null;
        $after = $change['after'] ?? null;
        $display = $change['display'] ?? ($change['text'] ?? '');
        $iconIds = $change['icon_ids'] ?? [];
        if (! is_string($operation) || ! in_array($operation, self::CHANGE_OPERATIONS, true)
            || ($field !== null && (! $this->boundedString($field, self::MAX_FIELD_BYTES) || trim($field) === ''))
            || ($before !== null && ! $this->boundedString($before, self::MAX_VALUE_BYTES))
            || ($after !== null && ! $this->boundedString($after, self::MAX_VALUE_BYTES))
            || ! $this->boundedString($display, self::MAX_DISPLAY_BYTES)
            || trim($display) === ''
            || ! is_array($iconIds)
            || ! array_is_list($iconIds)
            || count($iconIds) > self::MAX_ICON_IDS_PER_CHANGE) {
            throw new RuntimeException("The item history artifact for {$itemId} has an invalid change.");
        }
        foreach ($iconIds as $iconId) {
            if (! is_int($iconId) || $iconId < 0) {
                throw new RuntimeException("The item history artifact for {$itemId} has invalid change icons.");
            }
        }
    }

    private function validateRevisionDetail(mixed $detail, int $itemId): int
    {
        if (! is_array($detail)) {
            throw new RuntimeException("The item history artifact for {$itemId} has invalid revision detail.");
        }

        $name = $detail['name'] ?? null;
        $icon = $detail['icon'] ?? null;
        $lines = $detail['snapshot_lines'] ?? ($detail['display_lines'] ?? []);
        if (! $this->boundedString($name, self::MAX_NAME_BYTES)
            || trim($name) === ''
            || ! array_key_exists('icon', $detail)
            || ($icon !== null && (! is_int($icon) || $icon < 0))
            || ! is_array($lines)
            || ! array_is_list($lines)
            || $lines === []
            || count($lines) > self::MAX_SNAPSHOT_LINES_PER_REVISION) {
            throw new RuntimeException("The item history artifact for {$itemId} has invalid revision detail.");
        }
        foreach ($lines as $line) {
            if (! $this->boundedString($line, self::MAX_SNAPSHOT_LINE_BYTES) || trim($line) === '') {
                throw new RuntimeException("The item history artifact for {$itemId} has invalid snapshot lines.");
            }
        }

        $metadata = $detail['metadata'] ?? [];
        if (! is_array($metadata) || count($metadata) > self::MAX_DETAIL_METADATA_FIELDS) {
            throw new RuntimeException("The item history artifact for {$itemId} has invalid revision metadata.");
        }
        foreach ($metadata as $key => $value) {
            if (! $this->boundedString($key, self::MAX_FIELD_BYTES)
                || trim($key) === ''
                || ! $this->boundedString($value, self::MAX_METADATA_VALUE_BYTES)) {
                throw new RuntimeException("The item history artifact for {$itemId} has invalid revision metadata.");
            }
        }

        $links = $detail['links'] ?? [];
        if (! is_array($links) || ! array_is_list($links) || count($links) > self::MAX_DETAIL_LINKS) {
            throw new RuntimeException("The item history artifact for {$itemId} has invalid revision links.");
        }
        foreach ($links as $link) {
            if (! is_array($link)
                || ! $this->boundedString($link['text'] ?? null, self::MAX_LINK_TEXT_BYTES)
                || trim($link['text']) === ''
                || ! $this->boundedString($link['href'] ?? null, self::MAX_LINK_HREF_BYTES)
                || trim($link['href']) === '') {
                throw new RuntimeException("The item history artifact for {$itemId} has invalid revision links.");
            }
        }

        return count($lines);
    }

    /**
     * @param  list<array<string, mixed>>  $revisions
     * @return list<array{offset: int, length: int}>
     */
    private function pageSlices(array $revisions, int $pageSize, ?string $latestName): array
    {
        if ($revisions === []) {
            return [['offset' => 0, 'length' => 0]];
        }

        $pages = [];
        $offset = 0;
        $length = 0;
        $renderBytes = self::PAGE_RENDER_OVERHEAD_BYTES + $this->escapedRenderBytes($latestName);

        foreach ($revisions as $revision) {
            $revisionRenderBytes = $this->estimateRevisionRenderBytes($revision);
            if ($length > 0
                && ($length >= $pageSize
                    || $renderBytes + $revisionRenderBytes > self::MAX_PAGE_ESTIMATED_RENDER_BYTES)) {
                $pages[] = ['offset' => $offset, 'length' => $length];
                $offset += $length;
                $length = 0;
                $renderBytes = self::PAGE_RENDER_OVERHEAD_BYTES + $this->escapedRenderBytes($latestName);
            }

            if ($renderBytes + $revisionRenderBytes > self::MAX_PAGE_ESTIMATED_RENDER_BYTES) {
                throw new RuntimeException('An item history revision is too large to render safely.');
            }

            $length++;
            $renderBytes += $revisionRenderBytes;
        }

        if ($length > 0) {
            $pages[] = ['offset' => $offset, 'length' => $length];
        }

        return $pages;
    }

    /** @param array<string, mixed> $revision */
    private function estimateRevisionRenderBytes(array $revision): int
    {
        $changes = $revision['changes'];
        $detail = $revision['detail'];
        $lines = $detail['snapshot_lines'] ?? ($detail['display_lines'] ?? []);
        $bytes = self::REVISION_RENDER_OVERHEAD_BYTES;
        $bytes += max(1, count($changes)) * self::CHANGE_RENDER_OVERHEAD_BYTES;

        foreach ($changes as $change) {
            foreach (['operation', 'field', 'before', 'after'] as $key) {
                $bytes += $this->escapedRenderBytes($change[$key] ?? null);
            }
            $bytes += $this->escapedRenderBytes($change['display'] ?? ($change['text'] ?? ''));
            $bytes += $this->escapedRenderBytes($revision['source'] ?? null);
            $bytes += count($change['icon_ids'] ?? []) * self::ICON_RENDER_OVERHEAD_BYTES;
        }

        if (($detail['icon'] ?? null) !== null) {
            $bytes += self::ICON_RENDER_OVERHEAD_BYTES;
        }

        foreach ($lines as $line) {
            $bytes += self::SNAPSHOT_LINE_RENDER_OVERHEAD_BYTES + $this->escapedRenderBytes($line);
        }

        return $bytes;
    }

    private function escapedRenderBytes(mixed $value): int
    {
        return is_string($value) ? strlen($value) * self::HTML_ESCAPE_EXPANSION : 0;
    }

    private function boundedString(mixed $value, int $maximumBytes): bool
    {
        return is_string($value) && strlen($value) <= $maximumBytes;
    }

    private function validateDecodedComplexity(
        mixed $value,
        int $itemId,
        int $depth,
        int &$valueCount,
        int &$stringBytes,
    ): void {
        $valueCount++;
        if ($valueCount > self::MAX_JSON_VALUES || $depth > self::MAX_JSON_DEPTH) {
            throw new RuntimeException("The item history for item {$itemId} is too structurally complex.");
        }

        if (is_string($value)) {
            $bytes = strlen($value);
            $stringBytes += $bytes;
            if ($bytes > self::MAX_JSON_STRING_BYTES
                || $stringBytes > self::MAX_JSON_TOTAL_STRING_BYTES) {
                throw new RuntimeException("The item history for item {$itemId} contains oversized strings.");
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }
        if (count($value) > self::MAX_JSON_CONTAINER_ENTRIES) {
            throw new RuntimeException("The item history for item {$itemId} is too structurally complex.");
        }

        foreach ($value as $key => $nestedValue) {
            if (is_string($key)) {
                $keyBytes = strlen($key);
                $stringBytes += $keyBytes;
                if ($keyBytes > self::MAX_JSON_STRING_BYTES
                    || $stringBytes > self::MAX_JSON_TOTAL_STRING_BYTES) {
                    throw new RuntimeException("The item history for item {$itemId} contains oversized strings.");
                }
            }
            $this->validateDecodedComplexity(
                $nestedValue,
                $itemId,
                $depth + 1,
                $valueCount,
                $stringBytes,
            );
        }
    }

    /** @param array<string, mixed> $artifact @return array<string, mixed> */
    private function normalizeArtifact(array $artifact): array
    {
        foreach ($artifact['revisions'] as &$revision) {
            $revision['observed_precision'] = $revision['observed_precision']
                ?? ($revision['observed_at_precision'] ?? 'unknown');
            $revision['capture_sha256'] = $revision['capture_sha256']
                ?? data_get($revision, 'capture.sha256');

            foreach ($revision['changes'] as &$change) {
                $change = [
                    ...$change,
                    'operation' => $change['operation'] ?? 'unknown',
                    'field' => $change['field'] ?? null,
                    'before' => $change['before'] ?? null,
                    'after' => $change['after'] ?? null,
                    'display' => $change['display'] ?? ($change['text'] ?? ''),
                ];
            }
            unset($change);

            if (is_array($revision['detail'] ?? null)) {
                $revision['detail']['snapshot_lines'] = $revision['detail']['snapshot_lines']
                    ?? ($revision['detail']['display_lines'] ?? []);
            }
        }
        unset($revision);

        return $artifact;
    }

    private function nullableTimestamp(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D', $value) !== 1) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s',
            $value,
            new DateTimeZone('UTC'),
        );

        return $date !== false && $date->format('Y-m-d\TH:i:s') === $value;
    }

    private function validGeneratedAt(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $formats = [
            ['parse' => '!Y-m-d\TH:i:s\Z', 'round_trip' => 'Y-m-d\TH:i:s\Z'],
            ['parse' => '!Y-m-d\TH:i:s.v\Z', 'round_trip' => 'Y-m-d\TH:i:s.v\Z'],
        ];
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat(
                $format['parse'],
                $value,
                new DateTimeZone('UTC'),
            );
            if ($date !== false && $date->format($format['round_trip']) === $value) {
                return true;
            }
        }

        return false;
    }

    private function assertItemId(int $itemId): void
    {
        if ($itemId < 1) {
            throw new InvalidArgumentException('Item history item ID must be positive.');
        }
    }
}

<?php

namespace App\Services\SpellHistory;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class SnapshotLocator
{
    private const FILENAME_PATTERN = '/^spelldata_Live_(\d{4}-\d{2}-\d{2})_(\d{2})_(\d{2})_(\d{2})\.txt$/i';

    /** @var list<string> */
    private array $ignoredTextFiles = [];

    /**
     * @return list<SnapshotFile>
     */
    public function locate(string $sourceDirectory): array
    {
        $this->ignoredTextFiles = [];
        $sourceRoot = SafePath::existingDirectory($sourceDirectory, 'Snapshot source');
        $snapshots = [];

        // Do not use SPL's FilesystemIterator here. On Docker Desktop bind
        // mounts backed by Windows it can silently omit valid directory entries
        // (the production archive exposed 232 of 263 files), while scandir()
        // returns the complete directory listing.
        $entries = scandir($sourceRoot);
        if ($entries === false) {
            throw new RuntimeException("Unable to enumerate snapshot source: {$sourceRoot}");
        }

        foreach ($entries as $filename) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }

            $candidatePath = $sourceRoot.DIRECTORY_SEPARATOR.$filename;
            if (! is_file($candidatePath)) {
                continue;
            }

            if (preg_match(self::FILENAME_PATTERN, $filename, $matches) !== 1) {
                if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'txt') {
                    $this->ignoredTextFiles[] = $filename;
                }

                continue;
            }

            if (is_link($candidatePath)) {
                throw new RuntimeException("Snapshot files cannot be symbolic links: {$filename}");
            }

            $resolvedPath = SafePath::assertContained($candidatePath, $sourceRoot, "Snapshot {$filename}");
            $timestamp = "{$matches[1]} {$matches[2]}:{$matches[3]}:{$matches[4]}";
            // Lucy timestamps have no timezone. A fixed zone is used only to
            // prevent host DST rules from rejecting or shifting calendar fields.
            $observedAt = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $timestamp,
                new DateTimeZone('UTC'),
            );

            if ($observedAt === false || $observedAt->format('Y-m-d H:i:s') !== $timestamp) {
                throw new InvalidArgumentException("Snapshot filename has an invalid timestamp: {$filename}");
            }

            $snapshots[] = new SnapshotFile(
                path: $resolvedPath,
                filename: $filename,
                key: pathinfo($filename, PATHINFO_FILENAME),
                observedAt: $observedAt,
            );
        }

        usort($snapshots, static function (SnapshotFile $left, SnapshotFile $right): int {
            return [$left->observedAtIso(), $left->filename]
                <=> [$right->observedAtIso(), $right->filename];
        });

        if ($snapshots === []) {
            throw new InvalidArgumentException("No Lucy snapshot files were found in {$sourceRoot}.");
        }

        $seenKeys = [];
        $seenTimestamps = [];
        foreach ($snapshots as $snapshot) {
            if (isset($seenKeys[$snapshot->key])) {
                throw new RuntimeException("Duplicate snapshot key: {$snapshot->key}");
            }
            $seenKeys[$snapshot->key] = true;
            $timestamp = $snapshot->observedAtIso();
            if (isset($seenTimestamps[$timestamp])) {
                throw new RuntimeException("Duplicate snapshot timestamp: {$timestamp}");
            }
            $seenTimestamps[$timestamp] = true;
        }

        return $snapshots;
    }

    /** @return list<string> */
    public function ignoredTextFiles(): array
    {
        sort($this->ignoredTextFiles, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->ignoredTextFiles;
    }
}

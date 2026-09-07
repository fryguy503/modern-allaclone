<?php

namespace App\Services\ItemHistory;

use App\Services\SpellHistory\SafePath;
use RuntimeException;
use Throwable;

final class ItemHistoryActivator
{
    private const ACTIVATION_BACKUP_FILENAME = '.CURRENT.bak';

    private readonly ItemHistoryPermissions $permissions;

    public function __construct(?ItemHistoryPermissions $permissions = null)
    {
        $this->permissions = $permissions ?? new ItemHistoryPermissions;
    }

    /**
     * Atomically activate an installed dataset and return the previously active key.
     */
    public function activate(string $artifactDirectory, string $datasetKey): ?string
    {
        if (preg_match(ItemHistoryDataset::DATASET_KEY_PATTERN, $datasetKey) !== 1) {
            throw new RuntimeException('Refusing to activate an invalid item history dataset key.');
        }

        $artifactRoot = SafePath::existingDirectory($artifactDirectory, 'Item history artifact root');
        $datasetsPath = $artifactRoot.'/datasets';
        if (is_link($datasetsPath)) {
            throw new RuntimeException('Item history datasets directory cannot be a symbolic link.');
        }
        $datasetsRoot = SafePath::existingDirectory($datasetsPath, 'Item history datasets directory');
        SafePath::assertContained($datasetsRoot, $artifactRoot, 'Item history datasets directory');

        $datasetPath = $datasetsRoot.'/'.$datasetKey;
        if (is_link($datasetPath) || ! is_dir($datasetPath)) {
            throw new RuntimeException('Refusing to activate a missing or unsafe item history dataset.');
        }
        $datasetRoot = SafePath::assertContained($datasetPath, $datasetsRoot, 'Item history dataset');
        foreach (['manifest.json', 'COMPLETE.json'] as $requiredFile) {
            $path = $datasetRoot.'/'.$requiredFile;
            if (is_link($path) || ! is_file($path)) {
                throw new RuntimeException("Refusing to activate a dataset without a safe {$requiredFile} file.");
            }
            SafePath::assertContained($path, $datasetRoot, "Item history {$requiredFile}");
        }

        $itemsPath = $datasetRoot.'/items';
        if (is_link($itemsPath) || ! is_dir($itemsPath)) {
            throw new RuntimeException('Refusing to activate a dataset without a safe items directory.');
        }
        SafePath::assertContained($itemsPath, $datasetRoot, 'Item history items directory');

        $lockPath = $artifactRoot.'/.activation.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new RuntimeException('Item history activation lock must be a regular file.');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Unable to lock item history activation.');
        }
        try {
            $this->permissions->normalizeFile($lockPath, $artifactRoot, 'item history activation lock');
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock item history activation.');
            }
        } catch (Throwable $exception) {
            fclose($lock);

            throw $exception;
        }

        $temporary = $artifactRoot.'/.CURRENT-'.bin2hex(random_bytes(8)).'.tmp';
        $current = $artifactRoot.'/CURRENT';
        $backup = $artifactRoot.'/'.self::ACTIVATION_BACKUP_FILENAME;

        try {
            SafePath::assertContained($lockPath, $artifactRoot, 'Item history activation lock');
            $previous = $this->recoverInterruptedActivation($current, $backup);
            $this->writeActivationPointer($temporary, $datasetKey."\n");
            $this->permissions->normalizeFile($temporary, $artifactRoot, 'item history activation pointer');

            if (@rename($temporary, $current)) {
                return $previous;
            }

            // Windows cannot rename over an existing file. The activation lock keeps
            // cooperating readers out while the old pointer is moved and replaced.
            if (! is_file($current) || ! rename($current, $backup)) {
                throw new RuntimeException('Unable to replace the item history activation pointer.');
            }
            if (! rename($temporary, $current)) {
                if (! @rename($backup, $current)) {
                    throw new RuntimeException(
                        'Unable to install or restore the item history activation pointer; the previous pointer remains recoverable in CURRENT.bak.'
                    );
                }
                throw new RuntimeException('Unable to install the item history activation pointer.');
            }
            @unlink($backup);

            return $previous;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function recoverInterruptedActivation(string $current, string $backup): ?string
    {
        if (is_link($current)) {
            throw new RuntimeException('Item history CURRENT cannot be a symbolic link.');
        }
        if (is_link($backup)) {
            throw new RuntimeException('Item history CURRENT backup cannot be a symbolic link.');
        }

        $hasCurrent = file_exists($current);
        $hasBackup = file_exists($backup);
        if ($hasCurrent && ! is_file($current)) {
            throw new RuntimeException('Item history CURRENT must be a regular file.');
        }
        if ($hasBackup && ! is_file($backup)) {
            throw new RuntimeException('Item history CURRENT backup must be a regular file.');
        }
        $artifactRoot = dirname($current);
        if ($hasCurrent) {
            $this->permissions->normalizeFile($current, $artifactRoot, 'item history CURRENT');
        }
        if ($hasBackup) {
            $this->permissions->normalizeFile($backup, $artifactRoot, 'item history CURRENT backup');
        }

        if ($hasCurrent) {
            $previous = $this->readActivationPointer($current, 'CURRENT');
            if ($hasBackup) {
                $this->readActivationPointer($backup, 'CURRENT backup');
                if (! unlink($backup)) {
                    throw new RuntimeException('Unable to remove the stale item history CURRENT backup.');
                }
            }

            return $previous;
        }
        if (! $hasBackup) {
            return null;
        }

        $previous = $this->readActivationPointer($backup, 'CURRENT backup');
        if (! rename($backup, $current)) {
            throw new RuntimeException('Unable to recover the interrupted item history activation pointer.');
        }

        return $previous;
    }

    private function readActivationPointer(string $path, string $label): string
    {
        $bytes = filesize($path);
        $raw = file_get_contents($path);
        if ($bytes === false || $bytes < 64 || $bytes > 66 || $raw === false) {
            throw new RuntimeException("Item history {$label} has an invalid size.");
        }

        $key = rtrim($raw, "\r\n");
        if (($raw !== $key && $raw !== $key."\n" && $raw !== $key."\r\n")
            || preg_match(ItemHistoryDataset::DATASET_KEY_PATTERN, $key) !== 1) {
            throw new RuntimeException("Item history {$label} contains an invalid dataset key.");
        }

        return $key;
    }

    private function writeActivationPointer(string $path, string $pointer): void
    {
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to create the item history activation pointer.');
        }

        try {
            $offset = 0;
            $length = strlen($pointer);
            while ($offset < $length) {
                $written = fwrite($handle, substr($pointer, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to write the item history activation pointer.');
                }
                $offset += $written;
            }
            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('Unable to flush the item history activation pointer.');
            }
        } finally {
            fclose($handle);
        }
    }
}

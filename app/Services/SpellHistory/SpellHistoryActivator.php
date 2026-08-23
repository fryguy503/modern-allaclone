<?php

namespace App\Services\SpellHistory;

use RuntimeException;

final class SpellHistoryActivator
{
    private const ACTIVATION_BACKUP_FILENAME = '.CURRENT.bak';

    /**
     * Atomically activate an installed dataset and return the previously active key.
     */
    public function activate(string $artifactDirectory, string $datasetKey): ?string
    {
        if (preg_match(SpellHistoryArtifact::DATASET_KEY_PATTERN, $datasetKey) !== 1) {
            throw new RuntimeException('Refusing to activate an invalid dataset key.');
        }

        $artifactRoot = SafePath::existingDirectory($artifactDirectory, 'Spell history artifact root');
        $datasetsPath = $artifactRoot.'/datasets';
        if (is_link($datasetsPath)) {
            throw new RuntimeException('Spell history datasets directory cannot be a symbolic link.');
        }
        $datasetsRoot = SafePath::existingDirectory($datasetsPath, 'Spell history datasets directory');
        SafePath::assertContained($datasetsRoot, $artifactRoot, 'Spell history datasets directory');

        $datasetPath = $datasetsRoot.'/'.$datasetKey;
        if (is_link($datasetPath) || ! is_dir($datasetPath)) {
            throw new RuntimeException('Refusing to activate a missing or unsafe spell history dataset.');
        }
        SafePath::assertContained($datasetPath, $datasetsRoot, 'Spell history dataset');
        foreach (['manifest.json', 'COMPLETE.json'] as $requiredFile) {
            $path = $datasetPath.'/'.$requiredFile;
            if (is_link($path) || ! is_file($path)) {
                throw new RuntimeException("Refusing to activate a dataset without a safe {$requiredFile} file.");
            }
            SafePath::assertContained($path, $datasetPath, "Spell history {$requiredFile}");
        }

        $lockPath = $artifactRoot.'/.activation.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new RuntimeException('Spell history activation lock must be a regular file.');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock spell history activation.');
        }

        $temporary = $artifactRoot.'/.CURRENT-'.bin2hex(random_bytes(8)).'.tmp';
        $current = $artifactRoot.'/CURRENT';
        $backup = $artifactRoot.'/'.self::ACTIVATION_BACKUP_FILENAME;

        try {
            SafePath::assertContained($lockPath, $artifactRoot, 'Spell history activation lock');
            $previous = $this->recoverInterruptedActivation($current, $backup);
            $this->writeActivationPointer($temporary, $datasetKey."\n");

            if (@rename($temporary, $current)) {
                return $previous;
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
            $previous = $this->readActivationPointer($current, 'CURRENT');
            if ($hasBackup) {
                $this->readActivationPointer($backup, 'CURRENT backup');
                if (! unlink($backup)) {
                    throw new RuntimeException('Unable to remove the stale spell history CURRENT backup.');
                }
            }

            return $previous;
        }
        if (! $hasBackup) {
            return null;
        }

        $previous = $this->readActivationPointer($backup, 'CURRENT backup');
        if (! rename($backup, $current)) {
            throw new RuntimeException('Unable to recover the interrupted spell history activation pointer.');
        }

        return $previous;
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
}

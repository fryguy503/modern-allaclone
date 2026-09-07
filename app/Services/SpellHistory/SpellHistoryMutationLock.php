<?php

namespace App\Services\SpellHistory;

use RuntimeException;

final class SpellHistoryMutationLock
{
    public function exclusive(string $artifactDirectory, callable $operation): mixed
    {
        return $this->withLock($artifactDirectory, LOCK_EX, $operation);
    }

    public function shared(string $artifactDirectory, callable $operation): mixed
    {
        return $this->withLock($artifactDirectory, LOCK_SH, $operation);
    }

    private function withLock(string $artifactDirectory, int $mode, callable $operation): mixed
    {
        $artifactRoot = SafePath::existingDirectory($artifactDirectory, 'Spell history artifact root');
        $lockPath = $artifactRoot.'/.prune.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new RuntimeException('Spell history mutation lock must be a regular file.');
        }

        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, $mode)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock spell history dataset mutations.');
        }

        try {
            SafePath::assertContained($lockPath, $artifactRoot, 'Spell history mutation lock');

            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

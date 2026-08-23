<?php

namespace App\Services\SpellHistory;

use DateTimeImmutable;

final readonly class SnapshotFile
{
    public function __construct(
        public string $path,
        public string $filename,
        public string $key,
        public DateTimeImmutable $observedAt,
    ) {}

    public function observedAtIso(): string
    {
        return $this->observedAt->format('Y-m-d\TH:i:s');
    }
}

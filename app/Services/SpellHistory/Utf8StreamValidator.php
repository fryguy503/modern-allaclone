<?php

namespace App\Services\SpellHistory;

/**
 * Incremental strict UTF-8 validation without loading a snapshot into memory.
 */
final class Utf8StreamValidator
{
    private bool $valid = true;

    private string $carry = '';

    public function consume(string $bytes): void
    {
        if (! $this->valid) {
            return;
        }

        $data = $this->carry.$bytes;
        $carryBytes = $this->incompleteSuffixBytes($data);
        $this->carry = $carryBytes === 0 ? '' : substr($data, -$carryBytes);
        $complete = $carryBytes === 0 ? $data : substr($data, 0, -$carryBytes);
        if ($complete !== '' && ! mb_check_encoding($complete, 'UTF-8')) {
            $this->valid = false;
            $this->carry = '';
        }
    }

    public function isValidAtEnd(): bool
    {
        return $this->valid && $this->carry === '';
    }

    private function incompleteSuffixBytes(string $data): int
    {
        $length = strlen($data);
        if ($length === 0) {
            return 0;
        }

        $continuations = 0;
        for ($index = $length - 1; $index >= 0 && $continuations < 3; $index--) {
            $byte = ord($data[$index]);
            if ($byte >= 0x80 && $byte <= 0xBF) {
                $continuations++;

                continue;
            }

            $expected = match (true) {
                $byte >= 0xC2 && $byte <= 0xDF => 2,
                $byte >= 0xE0 && $byte <= 0xEF => 3,
                $byte >= 0xF0 && $byte <= 0xF4 => 4,
                default => 1,
            };
            $available = $continuations + 1;

            return $expected > $available ? $available : 0;
        }

        return 0;
    }
}

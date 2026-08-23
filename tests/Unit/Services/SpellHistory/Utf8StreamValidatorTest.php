<?php

namespace Tests\Unit\Services\SpellHistory;

use App\Services\SpellHistory\Utf8StreamValidator;
use PHPUnit\Framework\TestCase;

class Utf8StreamValidatorTest extends TestCase
{
    public function test_it_validates_multibyte_sequences_split_across_chunks(): void
    {
        $validator = new Utf8StreamValidator;
        $value = 'Virtue — 世界';

        for ($index = 0; $index < strlen($value); $index++) {
            $validator->consume($value[$index]);
        }

        $this->assertTrue($validator->isValidAtEnd());
    }

    public function test_it_rejects_invalid_and_incomplete_utf8(): void
    {
        $invalid = new Utf8StreamValidator;
        $invalid->consume("Valid\x93Windows");
        $this->assertFalse($invalid->isValidAtEnd());

        $incomplete = new Utf8StreamValidator;
        $incomplete->consume("Valid\xE2\x80");
        $this->assertFalse($incomplete->isValidAtEnd());
    }
}

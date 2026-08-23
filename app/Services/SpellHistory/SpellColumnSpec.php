<?php

namespace App\Services\SpellHistory;

final readonly class SpellColumnSpec
{
    public const KIND_IGNORED = 0;

    public const KIND_TEXT = 1;

    public const KIND_NUMBER = 2;

    public const SENTINEL_NONE = 0;

    public const SENTINEL_CLASS_LEVEL = 1;

    public const SENTINEL_EFFECT_ATTRIBUTE = 2;

    public const SENTINEL_REAGENT_ITEM = 3;

    public const SENTINEL_SPELL_REFERENCE = 4;

    public function __construct(
        public ?string $key,
        public int $kind,
        public int $sentinel = self::SENTINEL_NONE,
        public ?int $slot = null,
        public int $sourceIndex = 0,
        /** @var list<string> */
        public array $nullCompanions = [],
    ) {}
}

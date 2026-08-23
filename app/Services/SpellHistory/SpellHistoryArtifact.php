<?php

namespace App\Services\SpellHistory;

final class SpellHistoryArtifact
{
    public const SCHEMA = 'modern-allaclone.spell-history';

    public const FORMAT_VERSION = 4;

    public const DATASET_KEY_PATTERN = '/^[a-f0-9]{64}$/D';

    public const MAX_MANIFEST_BYTES = 4_194_304;

    public const MAX_SPELL_BYTES = 8_388_608;

    public const MAX_COMPLETION_BYTES = 4_096;

    public static function spellRelativePath(int $spellId): string
    {
        $hash = hash('sha256', (string) $spellId);

        return 'spells/'.substr($hash, 0, 2)."/{$spellId}.json";
    }
}

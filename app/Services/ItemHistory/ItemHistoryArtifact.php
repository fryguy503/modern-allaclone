<?php

namespace App\Services\ItemHistory;

final class ItemHistoryArtifact
{
    public const SCHEMA = 'modern-allaclone.item-history';

    public const FORMAT_VERSION = 1;

    public const DIRECT_DETAIL_PARSER_FORMAT_VERSION = 1;

    public const REVERSIBLE_DELTA_FORMAT_VERSION = 2;

    public const REVERSIBLE_DELTA_PARSER_FORMAT_VERSION = 2;

    public const REVERSIBLE_DELTA_CAPTURE_STRATEGY = 'reversible-delta-v1';

    public const MAX_ITEM_BYTES = 16_777_216;

    public static function itemRelativePath(int $itemId): string
    {
        $hash = hash('sha256', (string) $itemId);

        return 'items/'.substr($hash, 0, 2)."/{$itemId}.json";
    }
}

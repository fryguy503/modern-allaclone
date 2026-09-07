<?php

namespace App\Services\ItemHistory;

final class ItemHistoryDataset
{
    public const PACKAGE_SCHEMA = 'modern-allaclone.item-history-package';

    public const PACKAGE_VERSION = 1;

    public const DATASET_SCHEMA = 'modern-allaclone.item-history-dataset';

    public const DATASET_FORMAT_VERSION = 1;

    public const RELEASE_DESCRIPTOR = 'item-history-package.json';

    public const DATASET_KEY_PATTERN = '/^[a-f0-9]{64}$/D';

    public const MAX_DESCRIPTOR_BYTES = 262_144;

    public const MAX_MANIFEST_BYTES = 33_554_432;

    public const MAX_COMPLETION_BYTES = 262_144;

    public static function isSafeArchiveName(string $name): bool
    {
        if (strlen($name) > 200
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/D', $name) !== 1
            || basename($name) !== $name) {
            return false;
        }

        $stem = rtrim(explode('.', rtrim($name, ' .'), 2)[0], ' .');

        return preg_match('/^(?:CON|PRN|AUX|NUL|CLOCK\$|COM[1-9]|LPT[1-9])$/iD', $stem) !== 1;
    }
}

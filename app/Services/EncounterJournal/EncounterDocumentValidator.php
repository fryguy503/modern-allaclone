<?php

namespace App\Services\EncounterJournal;

final class EncounterDocumentValidator
{
    private array $errors = [];

    /** @return array{entry: ?array, errors: array<string>} */
    public function validate(array $entry, string $filename): array
    {
        $this->errors = [];
        $this->keys($entry, ['schema_version', 'slug', 'title', 'group', 'type', 'zone', 'npc_ids', 'status', 'spoiler', 'summary', 'overview', 'roles', 'phases', 'abilities', 'sections', 'loot', 'sources'], 'entry');
        if (($entry['schema_version'] ?? null) !== 1) {
            $this->errors[] = 'schema_version must be 1.';
        }
        $this->identifier($entry['slug'] ?? null, 'slug');
        if (($entry['slug'] ?? null) !== pathinfo($filename, PATHINFO_FILENAME)) {
            $this->errors[] = 'slug must match the JSON filename.';
        }
        foreach (['title' => 160, 'group' => 160, 'summary' => 1500] as $key => $length) {
            $this->text($entry[$key] ?? null, $key, $length);
        }
        $this->choice($entry['type'] ?? null, ['raid', 'group', 'event'], 'type');
        $this->choice($entry['status'] ?? null, ['draft', 'published'], 'status');
        if (! is_bool($entry['spoiler'] ?? null)) {
            $this->errors[] = 'spoiler must be a boolean.';
        }
        $zone = $this->object($entry['zone'] ?? null, 'zone');
        $this->keys($zone, ['short_name', 'name', 'version'], 'zone');
        if (! is_string($zone['short_name'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $zone['short_name'])) {
            $this->errors[] = 'zone.short_name must be a lowercase zone short name.';
        }
        $this->text($zone['name'] ?? null, 'zone.name', 160);
        $this->integer($zone['version'] ?? null, 'zone.version', 0);
        $entry['zone'] = $zone;
        $this->integerList($entry['npc_ids'] ?? null, 'npc_ids', 100);
        $this->textList($entry['overview'] ?? null, 'overview');

        foreach (['roles', 'phases', 'abilities', 'sections', 'loot'] as $key) {
            $entry[$key] ??= [];
            $this->list($entry[$key], $key, $key === 'abilities' ? 100 : 50);
        }
        $roleIds = [];
        foreach (is_array($entry['roles']) ? $entry['roles'] : [] as $index => $role) {
            $path = "roles.{$index}";
            $role = $this->object($role, $path);
            $this->keys($role, ['id', 'label', 'tips'], $path);
            $this->identifier($role['id'] ?? null, "{$path}.id");
            $this->text($role['label'] ?? null, "{$path}.label", 80);
            $role['tips'] ??= [];
            $this->textList($role['tips'], "{$path}.tips");
            if (is_string($role['id'] ?? null)) {
                $roleIds[] = $role['id'];
            }
            $entry['roles'][$index] = $role;
        }
        foreach (is_array($entry['phases']) ? $entry['phases'] : [] as $index => $phase) {
            $path = "phases.{$index}";
            $phase = $this->object($phase, $path);
            $this->keys($phase, ['id', 'label', 'trigger', 'description'], $path);
            $this->identifier($phase['id'] ?? null, "{$path}.id");
            $this->text($phase['label'] ?? null, "{$path}.label", 160);
            $this->text($phase['trigger'] ?? null, "{$path}.trigger", 300);
            $this->text($phase['description'] ?? null, "{$path}.description");
        }
        foreach (is_array($entry['abilities']) ? $entry['abilities'] : [] as $index => $ability) {
            $path = "abilities.{$index}";
            $ability = $this->object($ability, $path);
            $this->keys($ability, ['id', 'name', 'summary', 'description', 'tags', 'roles', 'spell_ids'], $path);
            $this->identifier($ability['id'] ?? null, "{$path}.id");
            $this->text($ability['name'] ?? null, "{$path}.name", 160);
            $this->text($ability['summary'] ?? null, "{$path}.summary", 1500);
            foreach (['description', 'tags', 'roles', 'spell_ids'] as $key) {
                $ability[$key] ??= [];
            }
            $this->textList($ability['description'], "{$path}.description");
            $this->textList($ability['tags'], "{$path}.tags", 20, 80);
            $this->textList($ability['roles'], "{$path}.roles", 50, 80);
            $this->integerList($ability['spell_ids'], "{$path}.spell_ids", 50);
            foreach (is_array($ability['roles']) ? $ability['roles'] : [] as $roleId) {
                if (! in_array($roleId, $roleIds, true)) {
                    $this->errors[] = "{$path}.roles contains an unknown role reference.";
                }
            }
            $entry['abilities'][$index] = $ability;
        }
        foreach (is_array($entry['sections']) ? $entry['sections'] : [] as $index => $section) {
            $path = "sections.{$index}";
            $section = $this->object($section, $path);
            $this->keys($section, ['id', 'title', 'paragraphs', 'bullets', 'items'], $path);
            $this->identifier($section['id'] ?? null, "{$path}.id");
            $this->text($section['title'] ?? null, "{$path}.title", 160);
            foreach (['paragraphs', 'bullets', 'items'] as $key) {
                $section[$key] ??= [];
            }
            $this->textList($section['paragraphs'], "{$path}.paragraphs");
            $this->textList($section['bullets'], "{$path}.bullets");
            $this->items($section['items'], "{$path}.items");
            $entry['sections'][$index] = $section;
        }
        foreach (is_array($entry['loot']) ? $entry['loot'] : [] as $index => $loot) {
            $path = "loot.{$index}";
            $loot = $this->object($loot, $path);
            $this->keys($loot, ['title', 'description', 'items'], $path);
            $this->text($loot['title'] ?? null, "{$path}.title", 160);
            $this->text($loot['description'] ?? null, "{$path}.description");
            $loot['items'] ??= [];
            $this->items($loot['items'], "{$path}.items");
            $entry['loot'][$index] = $loot;
        }
        foreach (['roles', 'phases', 'abilities', 'sections'] as $key) {
            $this->uniqueIds($entry[$key], $key);
        }
        if (isset($entry['sources']) || ($entry['status'] ?? '') === 'published') {
            $sources = $this->object($entry['sources'] ?? null, 'sources');
            $this->keys($sources, ['reviewed_at', 'revision', 'verification', 'files', 'notes'], 'sources');
            $date = $sources['reviewed_at'] ?? null;
            if (! is_string($date) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $this->errors[] = 'sources.reviewed_at must be a valid YYYY-MM-DD date.';
            }
            $this->text($sources['revision'] ?? null, 'sources.revision', 200);
            $this->choice($sources['verification'] ?? null, ['source-reviewed', 'live-verified'], 'sources.verification');
            $this->list($sources['files'] ?? null, 'sources.files', 100);
            if (empty($sources['files'])) {
                $this->errors[] = 'sources.files must contain at least one reviewed source.';
            }
            $paths = [];
            foreach (is_array($sources['files'] ?? null) ? $sources['files'] : [] as $index => $source) {
                $path = "sources.files.{$index}";
                $source = $this->object($source, $path);
                $this->keys($source, ['path', 'sha256'], $path);
                $sourcePath = $source['path'] ?? null;
                if (! $this->relativeSourcePath($sourcePath)) {
                    $this->errors[] = "{$path}.path must be a relative path without traversal, backslashes, or drive letters.";
                } elseif (in_array($sourcePath, $paths, true)) {
                    $this->errors[] = "{$path}.path duplicates a source file.";
                } else {
                    $paths[] = $sourcePath;
                }
                if (! is_string($source['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/Di', $source['sha256'])) {
                    $this->errors[] = "{$path}.sha256 must be a SHA-256 digest.";
                }
            }
            $sources['notes'] ??= [];
            $this->textList($sources['notes'], 'sources.notes');
            $entry['sources'] = $sources;
        } else {
            $entry['sources'] = null;
        }

        return ['entry' => $this->errors === [] ? $entry : null, 'errors' => $this->errors];
    }

    private function keys(array $value, array $allowed, string $path): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $key) {
            $this->errors[] = "{$path}.{$key} is not a supported field.";
        }
    }

    private function relativeSourcePath(mixed $path): bool
    {
        if (! is_string($path) || strlen($path) > 300 || str_contains($path, '\\') || str_contains($path, ':') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            // EQ quest filenames may include #, apostrophes and spaces. Reject
            // traversal, including Windows' normalization of trailing spaces.
            if (in_array(rtrim($segment, ' '), ['', '.', '..'], true)) {
                return false;
            }
        }

        return true;
    }

    private function object(mixed $value, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->errors[] = "{$path} must be an object.";

            return [];
        }

        return $value;
    }

    private function list(mixed $value, string $path, int $maximum = 50): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximum) {
            $this->errors[] = "{$path} must be a list with at most {$maximum} entries.";

            return false;
        }

        return true;
    }

    private function text(mixed $value, string $path, int $maximum = 5000): void
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            $this->errors[] = "{$path} must be nonempty text of at most {$maximum} characters.";
        }
    }

    private function textList(mixed $value, string $path, int $maximum = 50, int $textMaximum = 5000): void
    {
        if ($this->list($value, $path, $maximum)) {
            foreach ($value as $index => $text) {
                $this->text($text, "{$path}.{$index}", $textMaximum);
            }
        }
    }

    private function identifier(mixed $value, string $path): void
    {
        if (! is_string($value) || strlen($value) > 100 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value)) {
            $this->errors[] = "{$path} must be a lowercase kebab-case identifier of at most 100 characters.";
        }
    }

    private function integer(mixed $value, string $path, int $minimum = 1): void
    {
        if (! is_int($value) || $value < $minimum || $value > 2147483647) {
            $this->errors[] = "{$path} must be an integer between {$minimum} and 2147483647.";
        }
    }

    private function integerList(mixed $value, string $path, int $maximum): void
    {
        if ($this->list($value, $path, $maximum)) {
            foreach ($value as $index => $id) {
                $this->integer($id, "{$path}.{$index}");
            }
            if (count(array_unique($value, SORT_REGULAR)) !== count($value)) {
                $this->errors[] = "{$path} contains duplicate IDs.";
            }
        }
    }

    private function choice(mixed $value, array $allowed, string $path): void
    {
        if (! in_array($value, $allowed, true)) {
            $this->errors[] = "{$path} must be one of: ".implode(', ', $allowed).'.';
        }
    }

    private function uniqueIds(mixed $values, string $path): void
    {
        $ids = [];
        foreach (is_array($values) ? $values : [] as $value) {
            $id = is_array($value) ? ($value['id'] ?? null) : null;
            if (is_string($id)) {
                if (in_array($id, $ids, true)) {
                    $this->errors[] = "{$path} contains duplicate ID {$id}.";
                }
                $ids[] = $id;
            }
        }
    }

    private function items(mixed $items, string $path): void
    {
        if (! $this->list($items, $path, 100)) {
            return;
        }
        $ids = [];
        foreach ($items as $index => $item) {
            $itemPath = "{$path}.{$index}";
            $item = $this->object($item, $itemPath);
            $this->keys($item, ['id', 'name', 'quantity'], $itemPath);
            $this->integer($item['id'] ?? null, "{$itemPath}.id");
            $this->text($item['name'] ?? null, "{$itemPath}.name", 160);
            $this->integer($item['quantity'] ?? null, "{$itemPath}.quantity");
            if (is_int($item['id'] ?? null) && in_array($item['id'], $ids, true)) {
                $this->errors[] = "{$path} contains duplicate item ID {$item['id']}.";
            }
            $ids[] = $item['id'] ?? null;
        }
    }
}

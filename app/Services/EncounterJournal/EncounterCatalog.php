<?php

namespace App\Services\EncounterJournal;

use Illuminate\Container\Container;
use JsonException;

final class EncounterCatalog
{
    private string $directory;

    private ?array $entries = null;

    private array $errors = [];

    private int $fileCount = 0;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? config('everquest.encounter_journal.path', resource_path('data/encounters'));
    }

    public function all(bool $includeDrafts = false): array
    {
        $this->load();

        return array_values(array_filter($this->entries, fn (array $entry): bool => $includeDrafts || $entry['status'] === 'published'));
    }

    public function find(string $slug, bool $includeDrafts = false): ?array
    {
        $this->load();
        $entry = $this->entries[$slug] ?? null;

        return $entry !== null && ($includeDrafts || $entry['status'] === 'published') ? $entry : null;
    }

    public function forNpc(int $npcId, ?int $version = null, bool $includeDrafts = false): array
    {
        return array_values(array_filter($this->all($includeDrafts), fn (array $entry): bool => in_array($npcId, $entry['npc_ids'], true) && ($version === null || $entry['zone']['version'] === $version)));
    }

    public function forZone(string $shortName, int $version, bool $includeDrafts = false): array
    {
        return array_values(array_filter($this->all($includeDrafts), fn (array $entry): bool => $entry['zone']['short_name'] === $shortName && $entry['zone']['version'] === $version));
    }

    /**
     * Source-root verification is opt-in and never runs while serving public pages.
     *
     * @return array{valid: bool, files: int, entries: int, errors: array<array{file: string, message: string}>}
     */
    public function validationReport(?string $sourceRoot = null): array
    {
        $this->load();
        $errors = $this->errors;
        if ($sourceRoot !== null) {
            $root = realpath($sourceRoot);
            if ($root === false || ! is_dir($root)) {
                $errors[] = ['file' => 'sources', 'message' => 'Source root does not exist or is not a directory.'];
            } else {
                $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';
                foreach ($this->entries as $entry) {
                    foreach ($entry['sources']['files'] ?? [] as $source) {
                        $resolved = realpath($root.DIRECTORY_SEPARATOR.$source['path']);
                        $inside = $resolved !== false && (PHP_OS_FAMILY === 'Windows'
                            ? str_starts_with(strtolower(str_replace('\\', '/', $resolved)), strtolower($prefix))
                            : str_starts_with(str_replace('\\', '/', $resolved), $prefix));
                        if (! $inside || ! is_file($resolved)) {
                            $errors[] = ['file' => $entry['slug'].'.json', 'message' => "Source is missing or resolves outside source root: {$source['path']}."];
                        } elseif (($hash = @hash_file('sha256', $resolved)) === false || ! hash_equals(strtolower($source['sha256']), $hash)) {
                            $errors[] = ['file' => $entry['slug'].'.json', 'message' => "Source changed since review (SHA-256 mismatch): {$source['path']}."];
                        }
                    }
                }
            }
        }

        return ['valid' => $errors === [], 'files' => $this->fileCount, 'entries' => count($this->entries), 'errors' => $errors];
    }

    private function load(): void
    {
        if ($this->entries !== null) {
            return;
        }
        $this->entries = [];
        if (! is_dir($this->directory) || ! is_readable($this->directory)) {
            $this->error('catalog', 'Encounter content directory is missing or unreadable.');

            return;
        }
        $files = glob(rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR.'*.json');
        if ($files === false) {
            $this->error('catalog', 'Unable to list encounter content directory.');

            return;
        }
        sort($files, SORT_STRING);
        $this->fileCount = count($files);
        $validator = new EncounterDocumentValidator;
        $seen = [];
        $duplicates = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (! is_file($file) || filesize($file) > 1048576) {
                $this->error($name, 'Encounter file must be a regular JSON file no larger than 1 MiB.');

                continue;
            }
            $json = @file_get_contents($file);
            if ($json === false) {
                $this->error($name, 'Unable to read encounter file.');

                continue;
            }
            try {
                $entry = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->error($name, 'Invalid JSON: '.$exception->getMessage());

                continue;
            }
            if (! is_array($entry) || array_is_list($entry)) {
                $this->error($name, 'Encounter document must be a JSON object.');

                continue;
            }
            $slug = $entry['slug'] ?? null;
            if (is_string($slug)) {
                if (isset($seen[$slug])) {
                    $this->error($name, "Duplicate slug {$slug}; also present in {$seen[$slug]}.");
                    $duplicates[$slug] = true;
                }
                $seen[$slug] = $name;
            }
            $result = $validator->validate($entry, $name);
            foreach ($result['errors'] as $error) {
                $this->error($name, $error);
            }
            if ($result['entry'] !== null) {
                $this->entries[$slug] = $result['entry'];
            }
        }
        foreach (array_keys($duplicates) as $slug) {
            unset($this->entries[$slug]);
        }
        uasort($this->entries, fn (array $a, array $b): int => strnatcasecmp($a['zone']['name'], $b['zone']['name']) ?: strnatcasecmp($a['title'], $b['title']) ?: strcmp($a['slug'], $b['slug']));
    }

    private function error(string $file, string $message): void
    {
        $this->errors[] = compact('file', 'message');
        if (Container::getInstance()->bound('log')) {
            Container::getInstance()->make('log')->warning('Encounter journal: '.$message, ['file' => $file]);
        }
    }
}

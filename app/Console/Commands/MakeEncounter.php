<?php

namespace App\Console\Commands;

use App\Services\EncounterJournal\EncounterDocumentValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class MakeEncounter extends Command
{
    protected $signature = 'encounters:make {slug : Lowercase kebab-case encounter identifier}';

    protected $description = 'Create an unpublished encounter journal document without overwriting existing content';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        if (! is_string($slug) || strlen($slug) > 100 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
            $this->components->error('Use a lowercase kebab-case slug of at most 100 characters.');

            return self::INVALID;
        }
        $directory = config('everquest.encounter_journal.path', resource_path('data/encounters'));
        if (! is_string($directory) || $directory === '') {
            $this->components->error('Configure everquest.encounter_journal.path.');

            return self::INVALID;
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->components->error('Unable to create the encounter content directory.');

            return self::FAILURE;
        }
        $entry = [
            'schema_version' => 1,
            'slug' => $slug,
            'title' => Str::title(str_replace('-', ' ', $slug)),
            'group' => 'Encounter group',
            'type' => 'event',
            'zone' => ['short_name' => 'replacezone', 'name' => 'Replace with zone name', 'version' => 0],
            'npc_ids' => [],
            'status' => 'draft',
            'spoiler' => true,
            'summary' => 'Replace with a short encounter introduction.',
            'overview' => ['Describe the encounter and its objectives after reviewing the quest scripts.'],
            'roles' => [],
            'phases' => [],
            'abilities' => [],
            'sections' => [],
            'loot' => [],
        ];
        $result = (new EncounterDocumentValidator)->validate($entry, $slug.'.json');
        if ($result['errors'] !== []) {
            $this->components->error('Unable to validate the draft scaffold.');

            return self::FAILURE;
        }
        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$slug.'.json';
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            $this->components->error('Could not create document. It may already exist or the directory is not writable.');

            return self::FAILURE;
        }
        $json = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $written = fwrite($handle, $json);
        fclose($handle);
        if ($written !== strlen($json)) {
            @unlink($path);
            $this->components->error('Unable to write the complete encounter document.');

            return self::FAILURE;
        }
        $this->components->info('Created draft encounter: '.$path);
        $this->line('Fill in the encounter, add reviewed sources, then run encounters:validate before publishing.');

        return self::SUCCESS;
    }
}

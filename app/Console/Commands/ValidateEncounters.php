<?php

namespace App\Console\Commands;

use App\Services\EncounterJournal\EncounterCatalog;
use Illuminate\Console\Command;

final class ValidateEncounters extends Command
{
    protected $signature = 'encounters:validate {--source-root= : Quest repository root for checking reviewed source hashes}';

    protected $description = 'Validate encounter journal documents, including drafts, and optionally check for changed quest sources';

    public function handle(EncounterCatalog $catalog): int
    {
        $sourceRoot = $this->option('source-root');
        $report = $catalog->validationReport(is_string($sourceRoot) && trim($sourceRoot) !== '' ? $sourceRoot : null);
        foreach ($report['errors'] as $error) {
            $this->components->error($error['file'].': '.$error['message']);
        }
        if (! $report['valid']) {
            $this->components->error('Encounter validation failed.');

            return self::FAILURE;
        }
        $this->components->info("Validated {$report['entries']} encounter documents ({$report['files']} files), including drafts.");
        if ($sourceRoot === null || $sourceRoot === '') {
            $this->line('Source freshness was not checked. Use --source-root to compare reviewed source hashes.');
        }

        return self::SUCCESS;
    }
}

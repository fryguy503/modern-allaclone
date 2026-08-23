<?php

namespace App\Console\Commands;

use App\Services\SpellHistory\SpellHistoryPackager;
use Illuminate\Console\Command;
use Throwable;

final class PackageSpellHistory extends Command
{
    protected $signature = 'spell-history:package
        {--path= : Absolute spell-history artifact root}
        {--output= : Absolute directory for the ZIP, checksum, and release descriptor}
        {--name= : Optional ZIP filename}';

    protected $description = 'Package the active immutable spell-history dataset as verified release assets';

    public function handle(SpellHistoryPackager $packager): int
    {
        $pathOption = $this->option('path');
        $path = is_string($pathOption) && trim($pathOption) !== ''
            ? $pathOption
            : config('everquest.spell_history.artifact_path');
        if (! is_string($path) || trim($path) === '') {
            $this->components->error(
                'Provide --path or configure everquest.spell_history.artifact_path.',
            );

            return self::INVALID;
        }

        $outputOption = $this->option('output');
        $output = is_string($outputOption) && trim($outputOption) !== ''
            ? $outputOption
            : rtrim($path, '/\\').DIRECTORY_SEPARATOR.'releases';
        $nameOption = $this->option('name');
        $name = is_string($nameOption) && trim($nameOption) !== '' ? $nameOption : null;

        $this->components->info('Validating and packaging the active spell-history dataset.');

        try {
            $result = $packager->create(
                $path,
                $output,
                $name,
                function (string $stage, array $context): void {
                    $current = (int) ($context['current'] ?? 0);
                    $total = (int) ($context['total'] ?? 0);
                    if ($current !== 1 && $current !== $total && $current % 5_000 !== 0) {
                        return;
                    }
                    $verb = $stage === 'archive' ? 'Archived' : 'Validated';
                    $this->line("{$verb} ".number_format($current).'/'.number_format($total).' artifacts');
                },
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Packaged active dataset {$result['dataset']}");
        $this->table(
            ['Snapshots', 'Spells', 'Revisions', 'Dataset files', 'ZIP bytes'],
            [[
                number_format($result['stats']['snapshot_count']),
                number_format($result['stats']['spell_count']),
                number_format($result['stats']['revision_count']),
                number_format($result['stats']['file_count']),
                number_format($result['archive_bytes']),
            ]],
        );
        $this->line("ZIP: {$result['zip_path']}");
        $this->line("SHA-256: {$result['checksum_path']}");
        $this->line("Release descriptor: {$result['descriptor_path']}");

        return self::SUCCESS;
    }
}

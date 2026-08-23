<?php

namespace App\Console\Commands;

use App\Services\SpellHistory\SpellHistoryCompiler;
use Illuminate\Console\Command;
use Throwable;

final class BuildSpellHistory extends Command
{
    protected $signature = 'spell-history:build
        {--source= : Absolute directory containing Lucy spelldata snapshot files}
        {--output= : Absolute artifact root (defaults to storage/app/private/spell-history)}';

    protected $description = 'Compile Lucy spell snapshots into immutable, runtime-safe history artifacts';

    public function handle(SpellHistoryCompiler $compiler): int
    {
        $sourceOption = $this->option('source');
        $outputOption = $this->option('output');
        $source = is_string($sourceOption) && trim($sourceOption) !== ''
            ? $sourceOption
            : config('everquest.spell_history.source_path');
        $output = is_string($outputOption) && trim($outputOption) !== ''
            ? $outputOption
            : config('everquest.spell_history.artifact_path');

        if (! is_string($source) || trim($source) === '') {
            $this->components->error(
                'Provide --source or configure everquest.spell_history.source_path.',
            );

            return self::INVALID;
        }
        $output = is_string($output) && trim($output) !== ''
            ? $output
            : storage_path('app/private/spell-history');

        $this->components->info('Building spell history artifacts. Raw snapshots are read only by this command.');

        try {
            $result = $compiler->compile(
                $source,
                $output,
                function (string $stage, array $context): void {
                    if ($stage === 'hash') {
                        $current = (int) ($context['current'] ?? 0);
                        $total = (int) ($context['total'] ?? 0);
                        if ($current === 1 || $current === $total || $current % 25 === 0) {
                            $this->line("Hashed snapshot {$current}/{$total}");
                        }
                    } elseif ($stage === 'verify') {
                        $current = (int) ($context['current'] ?? 0);
                        $total = (int) ($context['total'] ?? 0);
                        if ($current === 1 || $current === $total || $current % 25 === 0) {
                            $this->line("Verified source snapshot {$current}/{$total}");
                        }
                    } elseif ($stage === 'compile') {
                        $this->line(sprintf(
                            'Compiled %s spells (%s revisions)',
                            number_format((int) ($context['spells'] ?? 0)),
                            number_format((int) ($context['revisions'] ?? 0)),
                        ));
                    } elseif ($stage === 'warning') {
                        $this->components->warn((string) ($context['message'] ?? 'Spell history compiler warning.'));
                    }
                },
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $verb = $result['reused'] ? 'Activated existing' : 'Built and activated';
        $this->components->info("{$verb} dataset {$result['dataset']}");
        $this->table(
            ['Snapshots', 'Spells', 'Revisions', 'Artifact bytes'],
            [[
                number_format($result['snapshots']),
                number_format($result['spells']),
                number_format($result['revisions']),
                number_format($result['bytes']),
            ]],
        );

        return self::SUCCESS;
    }
}

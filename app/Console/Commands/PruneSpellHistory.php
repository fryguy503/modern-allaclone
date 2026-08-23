<?php

namespace App\Console\Commands;

use App\Services\SpellHistory\SpellHistoryArtifactPruner;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class PruneSpellHistory extends Command
{
    private const DEFAULT_KEEP_RECENT = 2;

    private const DEFAULT_MINIMUM_AGE_HOURS = 24;

    private const MAXIMUM_KEEP_RECENT = 10_000;

    private const MAXIMUM_AGE_HOURS = 876_000;

    protected $signature = 'spell-history:prune
        {--path= : Absolute spell-history artifact root}
        {--keep-recent= : Recent inactive rollback datasets to retain (default: 2)}
        {--minimum-age-hours= : Grace period after activation/completion (default: 24)}
        {--apply : Permanently remove eligible datasets; otherwise only preview}';

    protected $description = 'Safely preview or prune inactive immutable spell-history datasets';

    public function handle(SpellHistoryArtifactPruner $pruner): int
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

        try {
            $keepRecent = $this->nonNegativeIntegerOption(
                $this->option('keep-recent'),
                config('everquest.spell_history.prune_keep_recent', self::DEFAULT_KEEP_RECENT),
                'keep-recent',
                self::MAXIMUM_KEEP_RECENT,
            );
            $minimumAgeHours = $this->nonNegativeIntegerOption(
                $this->option('minimum-age-hours'),
                config('everquest.spell_history.prune_minimum_age_hours', self::DEFAULT_MINIMUM_AGE_HOURS),
                'minimum-age-hours',
                self::MAXIMUM_AGE_HOURS,
            );
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        $this->components->info($apply
            ? 'Pruning eligible inactive spell-history datasets.'
            : 'DRY RUN: no spell-history datasets will be removed.');

        try {
            $result = $pruner->prune(
                $path,
                $keepRecent,
                $minimumAgeHours * 3_600,
                $apply,
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = array_map(static fn (array $dataset): array => [
            $dataset['dataset'],
            number_format($dataset['age_seconds'] / 3_600, 1),
            str_replace('_', ' ', $dataset['action']),
        ], $result['datasets']);
        foreach ($result['tombstones'] as $tombstone) {
            $rows[] = [
                $tombstone['dataset'],
                '—',
                str_replace('_', ' ', $tombstone['action'])." ({$tombstone['entry']})",
            ];
        }
        $this->table(
            ['Dataset', 'Age (hours)', 'Decision'],
            $rows,
        );

        foreach ($result['skipped'] as $skipped) {
            $this->components->warn("Skipped {$skipped['entry']}: {$skipped['reason']}");
        }

        if ($apply) {
            $this->components->info("Removed {$result['deleted_count']} inactive dataset(s).");
        } else {
            $this->components->info(
                "{$result['would_delete_count']} inactive dataset(s) would be removed. Re-run with --apply to proceed.",
            );
        }

        return self::SUCCESS;
    }

    private function nonNegativeIntegerOption(
        mixed $option,
        mixed $configuredDefault,
        string $name,
        int $maximum,
    ): int {
        $value = $option;
        if ($value === null || $value === '') {
            $value = $configuredDefault;
        }
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            $parsed = (int) $value;
        } else {
            throw new InvalidArgumentException("--{$name} must be a non-negative integer.");
        }
        if ($parsed < 0 || $parsed > $maximum) {
            throw new InvalidArgumentException("--{$name} must be between 0 and {$maximum}.");
        }

        return $parsed;
    }
}

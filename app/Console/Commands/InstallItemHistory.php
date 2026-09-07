<?php

namespace App\Console\Commands;

use App\Services\ItemHistory\ItemHistoryInstaller;
use Illuminate\Console\Command;
use Throwable;

final class InstallItemHistory extends Command
{
    protected $signature = 'item-history:install
        {--file= : Absolute path to an item-history ZIP package}
        {--sha256= : SHA-256 digest (required for --file; optional pinned override for --release)}
        {--release= : Exact GitHub release tag to install}
        {--path= : Absolute item-history artifact root}
        {--no-activate : Install and validate without changing CURRENT}';

    protected $description = 'Safely download or install a verified immutable item-history dataset';

    public function handle(ItemHistoryInstaller $installer): int
    {
        $file = $this->stringOption('file');
        $release = $this->stringOption('release');
        $sha256 = $this->stringOption('sha256');
        if (($file === null) === ($release === null)) {
            $this->components->error('Provide exactly one of --file or --release.');

            return self::INVALID;
        }
        if ($file !== null && $sha256 === null) {
            $this->components->error('--sha256 is required with --file.');

            return self::INVALID;
        }

        $path = $this->stringOption('path') ?? config('everquest.item_history.artifact_path');
        if (! is_string($path) || trim($path) === '') {
            $this->components->error('Provide --path or configure everquest.item_history.artifact_path.');

            return self::INVALID;
        }

        $limits = [
            'max_download_bytes' => config('everquest.item_history.max_download_bytes', 1_073_741_824),
            'max_unpacked_bytes' => config('everquest.item_history.max_unpacked_bytes', 3_221_225_472),
            'max_files' => config('everquest.item_history.max_files', 200_000),
            'connect_timeout' => config('everquest.item_history.connect_timeout', 15),
            'download_timeout' => config('everquest.item_history.download_timeout', 1_800),
        ];
        $activate = ! (bool) $this->option('no-activate');
        $progress = function (string $stage, array $context): void {
            if ($stage === 'download') {
                $this->line('Downloading '.$context['asset'].' ('.number_format((int) $context['bytes']).' bytes)');

                return;
            }

            $current = (int) ($context['current'] ?? 0);
            $total = (int) ($context['total'] ?? 0);
            if ($current === 0 || ($current !== $total && $current % 5_000 !== 0)) {
                return;
            }
            $verb = $stage === 'extract' ? 'Extracted' : 'Validated';
            $this->line("{$verb} ".number_format($current).'/'.number_format($total).' files');
        };

        $this->components->info($activate
            ? 'Installing and activating a verified item-history dataset.'
            : 'Installing a verified item-history dataset without activation.');

        try {
            if ($file !== null) {
                $result = $installer->installFromFile(
                    $file,
                    $sha256,
                    $path,
                    $activate,
                    $limits,
                    $progress,
                );
            } else {
                $repository = config('everquest.item_history.release_repository');
                if (! is_string($repository) || trim($repository) === '') {
                    $this->components->error(
                        'Configure everquest.item_history.release_repository before using --release.',
                    );

                    return self::INVALID;
                }
                if ($sha256 === null) {
                    $checksums = config('everquest.item_history.release_checksums', []);
                    $configuredChecksum = is_array($checksums) ? ($checksums[$release] ?? null) : null;
                    $sha256 = is_string($configuredChecksum) && trim($configuredChecksum) !== ''
                        ? trim($configuredChecksum)
                        : null;
                }
                if ($sha256 === null) {
                    $this->components->error(
                        'Provide --sha256 or configure a pinned checksum for this release tag.',
                    );

                    return self::INVALID;
                }
                $result = $installer->installFromRelease(
                    $release,
                    $repository,
                    $sha256,
                    $path,
                    $activate,
                    $limits,
                    $progress,
                );
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $action = $result['reused'] ? 'Verified existing' : 'Installed';
        $state = $result['activated'] ? ' and activated' : ' without activation';
        $this->components->info("{$action} dataset {$result['dataset']}{$state}.");
        $this->table(
            ['Items', 'Revisions', 'Dataset files', 'Unpacked bytes'],
            [[
                number_format($result['stats']['item_count']),
                number_format($result['stats']['revision_count']),
                number_format($result['stats']['file_count']),
                number_format($result['stats']['unpacked_bytes']),
            ]],
        );
        $this->line("Dataset path: {$result['path']}");

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

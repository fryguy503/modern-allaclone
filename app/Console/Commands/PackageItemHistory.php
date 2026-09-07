<?php

namespace App\Console\Commands;

use App\Services\ItemHistory\ItemHistoryPackager;
use Illuminate\Console\Command;
use Throwable;

final class PackageItemHistory extends Command
{
    protected $signature = 'item-history:package
        {--path= : Absolute item-history artifact root}
        {--output= : Absolute directory for the ZIP, checksum, and release descriptor}
        {--name= : Optional ZIP filename}
        {--workspace= : Completed private crawler workspace required for a flat artifact root}
        {--crawler-artifact-root-identity= : Explicit config artifact_root identity when packaging through a mount alias}
        {--crawler-workspace-identity= : Explicit config workspace identity when packaging through a mount alias}';

    protected $description = 'Package complete item-history artifacts as verified immutable release assets';

    public function handle(ItemHistoryPackager $packager): int
    {
        $pathOption = $this->option('path');
        $path = is_string($pathOption) && trim($pathOption) !== ''
            ? trim($pathOption)
            : config('everquest.item_history.artifact_path');
        if (! is_string($path) || trim($path) === '') {
            $this->components->error(
                'Provide --path or configure everquest.item_history.artifact_path.',
            );

            return self::INVALID;
        }

        $outputOption = $this->option('output');
        $output = is_string($outputOption) && trim($outputOption) !== ''
            ? trim($outputOption)
            : rtrim($path, '/\\').DIRECTORY_SEPARATOR.'releases';
        $nameOption = $this->option('name');
        $name = is_string($nameOption) && trim($nameOption) !== '' ? trim($nameOption) : null;
        $workspaceOption = $this->option('workspace');
        $workspace = is_string($workspaceOption) && trim($workspaceOption) !== ''
            ? trim($workspaceOption)
            : null;
        $identityOption = $this->option('crawler-artifact-root-identity');
        $crawlerArtifactRootIdentity = is_string($identityOption) && trim($identityOption) !== ''
            ? trim($identityOption)
            : null;
        $workspaceIdentityOption = $this->option('crawler-workspace-identity');
        $crawlerWorkspaceIdentity = is_string($workspaceIdentityOption) && trim($workspaceIdentityOption) !== ''
            ? trim($workspaceIdentityOption)
            : null;

        $this->components->info('Validating and packaging the complete item-history dataset.');

        try {
            $result = $packager->create(
                $path,
                $output,
                $name,
                function (string $stage, array $context): void {
                    if (! in_array($stage, ['validate', 'archive'], true)) {
                        return;
                    }
                    $current = (int) ($context['current'] ?? 0);
                    $total = (int) ($context['total'] ?? 0);
                    if ($current !== 1 && $current !== $total && $current % 5_000 !== 0) {
                        return;
                    }
                    $verb = $stage === 'archive' ? 'Archived' : 'Validated';
                    $suffix = $total > 0 ? '/'.number_format($total) : '';
                    $this->line("{$verb} ".number_format($current).$suffix.' artifacts');
                },
                $workspace,
                $crawlerArtifactRootIdentity,
                $crawlerWorkspaceIdentity,
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Packaged item-history dataset {$result['dataset']}");
        $this->table(
            ['Items', 'Revisions', 'Dataset files', 'ZIP bytes'],
            [[
                number_format($result['stats']['item_count']),
                number_format($result['stats']['revision_count']),
                number_format($result['stats']['file_count']),
                number_format($result['archive_bytes']),
            ]],
        );
        $this->line("ZIP: {$result['zip_path']}");
        $this->line("SHA-256: {$result['checksum_path']}");
        $this->line("Release descriptor: {$result['descriptor_path']}");
        foreach ($result['warnings'] ?? [] as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }
}

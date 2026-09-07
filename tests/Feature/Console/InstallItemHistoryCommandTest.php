<?php

namespace Tests\Feature\Console;

use App\Services\ItemHistory\ItemHistoryMutationLock;
use App\Services\ItemHistory\ItemHistoryPackager;
use Illuminate\Console\Command;
use Tests\Support\CreatesItemHistoryPackageDataset;
use Tests\TestCase;
use ZipArchive;

class InstallItemHistoryCommandTest extends TestCase
{
    use CreatesItemHistoryPackageDataset;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not installed.');
        }
        $this->temporaryDirectory = sys_get_temp_dir().'/item-history-install-command-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/source', 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory)) {
            $this->removeItemHistoryPackageTree($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function test_command_installs_a_verified_local_package_without_activation(): void
    {
        $this->createLegacyItemHistoryArtifacts($this->temporaryDirectory.'/source', [13_405, 20_542]);
        $release = (new ItemHistoryPackager(new ItemHistoryMutationLock))->create(
            $this->temporaryDirectory.'/source',
            $this->temporaryDirectory.'/release',
        );
        $installedRoot = $this->temporaryDirectory.'/installed';

        $this->artisan('item-history:install', [
            '--file' => $release['zip_path'],
            '--sha256' => $release['sha256'],
            '--path' => $installedRoot,
            '--no-activate' => true,
        ])
            ->expectsOutputToContain('Installing a verified item-history dataset without activation.')
            ->expectsOutputToContain("Installed dataset {$release['dataset']} without activation.")
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileExists($installedRoot.'/datasets/'.$release['dataset'].'/manifest.json');
        $this->assertFileExists($installedRoot.'/datasets/'.$release['dataset'].'/COMPLETE.json');
        $this->assertFileDoesNotExist($installedRoot.'/CURRENT');
    }

    public function test_command_requires_exactly_one_package_source(): void
    {
        $this->artisan('item-history:install')
            ->expectsOutputToContain('Provide exactly one of --file or --release.')
            ->assertExitCode(Command::INVALID);

        $this->artisan('item-history:install', [
            '--file' => $this->temporaryDirectory.'/package.zip',
            '--release' => 'item-history-data-v2-2026-09-06',
            '--sha256' => str_repeat('a', 64),
        ])
            ->expectsOutputToContain('Provide exactly one of --file or --release.')
            ->assertExitCode(Command::INVALID);
    }

    public function test_command_requires_an_independently_pinned_checksum(): void
    {
        $this->artisan('item-history:install', [
            '--file' => $this->temporaryDirectory.'/package.zip',
        ])
            ->expectsOutputToContain('--sha256 is required with --file.')
            ->assertExitCode(Command::INVALID);

        config()->set('everquest.item_history.release_checksums', []);
        $this->artisan('item-history:install', [
            '--release' => 'item-history-data-v2-2026-09-06',
        ])
            ->expectsOutputToContain('Provide --sha256 or configure a pinned checksum for this release tag.')
            ->assertExitCode(Command::INVALID);
    }
}

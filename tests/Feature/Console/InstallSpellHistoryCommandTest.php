<?php

namespace Tests\Feature\Console;

use App\Services\SpellHistory\SpellHistoryMutationLock;
use App\Services\SpellHistory\SpellHistoryPackager;
use Illuminate\Console\Command;
use Tests\Support\CreatesSpellHistoryPackageDataset;
use Tests\TestCase;
use ZipArchive;

class InstallSpellHistoryCommandTest extends TestCase
{
    use CreatesSpellHistoryPackageDataset;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not installed.');
        }
        $this->temporaryDirectory = sys_get_temp_dir().'/spell-history-install-command-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/source/datasets', 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory)) {
            $this->removePackageTree($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function test_command_installs_a_verified_local_package_without_activation(): void
    {
        $dataset = str_repeat('f', 64);
        $this->createPackageDataset($this->temporaryDirectory.'/source', $dataset);
        $release = (new SpellHistoryPackager(new SpellHistoryMutationLock))->create(
            $this->temporaryDirectory.'/source',
            $this->temporaryDirectory.'/release',
        );
        $installed = $this->temporaryDirectory.'/installed';

        $this->artisan('spell-history:install', [
            '--file' => $release['zip_path'],
            '--sha256' => $release['sha256'],
            '--path' => $installed,
            '--no-activate' => true,
        ])
            ->expectsOutputToContain("Installed dataset {$dataset} without activation")
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileExists($installed.'/datasets/'.$dataset.'/manifest.json');
        $this->assertFileDoesNotExist($installed.'/CURRENT');
    }

    public function test_command_requires_exactly_one_source(): void
    {
        $this->artisan('spell-history:install')
            ->expectsOutputToContain('Provide exactly one of --file or --release.')
            ->assertExitCode(Command::INVALID);
    }

    public function test_release_install_requires_an_independent_pinned_checksum(): void
    {
        config()->set('everquest.spell_history.release_repository', 'fryguy503/modern-allaclone');
        config()->set('everquest.spell_history.release_checksums', []);

        $this->artisan('spell-history:install', [
            '--release' => 'unconfigured-release',
            '--path' => $this->temporaryDirectory.'/installed',
        ])
            ->expectsOutputToContain('Provide --sha256 or configure a pinned checksum')
            ->assertExitCode(Command::INVALID);
    }
}

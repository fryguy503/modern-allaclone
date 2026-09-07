<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Command;
use Tests\Support\CreatesSpellHistoryPackageDataset;
use Tests\TestCase;
use ZipArchive;

class PackageSpellHistoryCommandTest extends TestCase
{
    use CreatesSpellHistoryPackageDataset;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not installed.');
        }
        $this->temporaryDirectory = sys_get_temp_dir().'/spell-history-package-command-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/artifacts/datasets', 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory)) {
            $this->removePackageTree($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function test_command_packages_the_configured_current_dataset(): void
    {
        $artifactRoot = $this->temporaryDirectory.'/artifacts';
        $outputRoot = $this->temporaryDirectory.'/release';
        $dataset = str_repeat('e', 64);
        $this->createPackageDataset($artifactRoot, $dataset);

        $this->artisan('spell-history:package', [
            '--path' => $artifactRoot,
            '--output' => $outputRoot,
            '--name' => 'spell-history-test.zip',
        ])
            ->expectsOutputToContain("Packaged active dataset {$dataset}")
            ->expectsOutputToContain('spell-history-test.zip.sha256')
            ->expectsOutputToContain('spell-history-package.json')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileExists($outputRoot.'/spell-history-test.zip');
        $this->assertFileExists($outputRoot.'/spell-history-test.zip.sha256');
        $this->assertFileExists($outputRoot.'/spell-history-package.json');
    }
}

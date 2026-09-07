<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Command;
use Tests\Support\CreatesItemHistoryPackageDataset;
use Tests\TestCase;
use ZipArchive;

class PackageItemHistoryCommandTest extends TestCase
{
    use CreatesItemHistoryPackageDataset;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not installed.');
        }
        $this->temporaryDirectory = sys_get_temp_dir().'/item-history-package-command-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/artifacts', 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory)) {
            $this->removeItemHistoryPackageTree($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function test_command_packages_the_configured_flat_dataset(): void
    {
        $artifactRoot = $this->temporaryDirectory.'/artifacts';
        $outputRoot = $this->temporaryDirectory.'/release';
        $this->createLegacyItemHistoryArtifacts($artifactRoot);
        config()->set('everquest.item_history.artifact_path', $artifactRoot);

        $this->artisan('item-history:package', [
            '--output' => $outputRoot,
            '--name' => 'item-history-test.zip',
        ])
            ->expectsOutputToContain('Packaged item-history dataset')
            ->expectsOutputToContain('item-history-test.zip.sha256')
            ->expectsOutputToContain('item-history-package.json')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileExists($outputRoot.'/item-history-test.zip');
        $this->assertFileExists($outputRoot.'/item-history-test.zip.sha256');
        $this->assertFileExists($outputRoot.'/item-history-package.json');
    }

    public function test_command_rejects_a_missing_artifact_path(): void
    {
        config()->set('everquest.item_history.artifact_path', null);

        $this->artisan('item-history:package')
            ->expectsOutputToContain('Provide --path or configure everquest.item_history.artifact_path.')
            ->assertExitCode(Command::INVALID);
    }

    public function test_command_accepts_explicit_cross_platform_crawler_path_identities(): void
    {
        $artifactRoot = $this->temporaryDirectory.'/artifacts';
        $workspace = $this->temporaryDirectory.'/mounted-crawler-workspace';
        $windowsWorkspace = 'F:\\release-host\\private\\lucy-item-history-crawl';
        $windowsArtifactRoot = 'F:\\release-host\\private\\item-history';
        $this->createLegacyItemHistoryArtifacts($artifactRoot);
        $this->createCompletedItemHistoryCrawlerWorkspace(
            $artifactRoot,
            [20_542],
            $workspace,
            $windowsArtifactRoot,
            $windowsWorkspace,
        );
        config()->set('everquest.item_history.artifact_path', $artifactRoot);

        $this->artisan('item-history:package', [
            '--output' => $this->temporaryDirectory.'/mounted-release',
            '--workspace' => $workspace,
            '--crawler-artifact-root-identity' => $windowsArtifactRoot,
            '--crawler-workspace-identity' => $windowsWorkspace,
        ])->assertExitCode(Command::SUCCESS);
    }
}

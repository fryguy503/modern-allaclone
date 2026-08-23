<?php

namespace Tests\Feature\Console;

use App\Services\SpellHistory\SpellCanonicalizer;
use App\Services\SpellHistory\SpellHistoryArtifact;
use Illuminate\Console\Command;
use Tests\TestCase;

class PruneSpellHistoryCommandTest extends TestCase
{
    private string $temporaryDirectory;

    private string $artifactDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().'/spell-history-prune-command-'.bin2hex(random_bytes(8));
        $this->artifactDirectory = $this->temporaryDirectory.'/artifacts';
        mkdir($this->artifactDirectory.'/datasets', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_command_is_a_dry_run_until_apply_is_explicit(): void
    {
        $active = str_repeat('a', 64);
        $expired = str_repeat('b', 64);
        $this->createDataset($active);
        $this->createDataset($expired);
        file_put_contents($this->artifactDirectory.'/CURRENT', $active."\n");
        touch($this->artifactDirectory.'/datasets/'.$active.'/COMPLETE.json', time() - 7_200);
        touch($this->artifactDirectory.'/datasets/'.$expired.'/COMPLETE.json', time() - 7_200);
        touch($this->artifactDirectory.'/CURRENT', time() - 3_600);

        $options = [
            '--path' => $this->artifactDirectory,
            '--keep-recent' => '0',
            '--minimum-age-hours' => '0',
        ];
        $this->artisan('spell-history:prune', $options)
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('1 inactive dataset(s) would be removed')
            ->assertExitCode(Command::SUCCESS);
        $this->assertDirectoryExists($this->artifactDirectory.'/datasets/'.$expired);

        $this->artisan('spell-history:prune', [...$options, '--apply' => true])
            ->expectsOutputToContain('Removed 1 inactive dataset(s)')
            ->assertExitCode(Command::SUCCESS);
        $this->assertDirectoryExists($this->artifactDirectory.'/datasets/'.$active);
        $this->assertDirectoryDoesNotExist($this->artifactDirectory.'/datasets/'.$expired);
    }

    private function createDataset(string $datasetKey): void
    {
        $directory = $this->artifactDirectory.'/datasets/'.$datasetKey;
        mkdir($directory, 0755, true);
        $manifest = [
            'schema' => SpellHistoryArtifact::SCHEMA,
            'artifact_type' => 'manifest',
            'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
            'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
            'dataset' => $datasetKey,
            'snapshots' => [[]],
            'stats' => [
                'snapshot_count' => 1,
                'spell_count' => 1,
                'revision_count' => 1,
            ],
        ];
        $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($directory.'/manifest.json', $manifestJson);
        file_put_contents($directory.'/COMPLETE.json', json_encode([
            'schema' => SpellHistoryArtifact::SCHEMA,
            'artifact_type' => 'completion',
            'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
            'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
            'dataset' => $datasetKey,
            'manifest_sha256' => hash('sha256', $manifestJson),
            'manifest_bytes' => strlen($manifestJson),
            'spell_count' => 1,
            'revision_count' => 1,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
                }
            }
        }
        @rmdir($path);
    }
}

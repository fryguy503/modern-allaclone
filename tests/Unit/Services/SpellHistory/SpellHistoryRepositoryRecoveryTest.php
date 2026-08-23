<?php

namespace Tests\Unit\Services\SpellHistory;

use App\Services\SpellHistory\SpellCanonicalizer;
use App\Services\SpellHistory\SpellHistoryArtifact;
use App\Services\SpellHistory\SpellHistoryRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SpellHistoryRepositoryRecoveryTest extends TestCase
{
    private string $temporaryDirectory;

    private string $artifactDirectory;

    private string $datasetKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().'/spell-history-repository-recovery-'.bin2hex(random_bytes(8));
        $this->artifactDirectory = $this->temporaryDirectory.'/artifacts';
        $this->datasetKey = str_repeat('a', 64);
        $this->createDataset();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_repository_uses_a_valid_backup_when_current_is_absent(): void
    {
        file_put_contents($this->artifactDirectory.'/.CURRENT.bak', $this->datasetKey."\n");

        $manifest = (new SpellHistoryRepository($this->artifactDirectory))->manifest();

        $this->assertSame($this->datasetKey, $manifest['dataset']);
    }

    public function test_repository_does_not_fall_back_when_current_exists_but_is_invalid(): void
    {
        file_put_contents($this->artifactDirectory.'/.CURRENT.bak', $this->datasetKey."\n");
        file_put_contents($this->artifactDirectory.'/CURRENT', "invalid\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CURRENT has an invalid size');
        (new SpellHistoryRepository($this->artifactDirectory))->manifest();
    }

    private function createDataset(): void
    {
        $directory = $this->artifactDirectory.'/datasets/'.$this->datasetKey;
        mkdir($directory, 0755, true);
        $manifest = [
            'schema' => SpellHistoryArtifact::SCHEMA,
            'artifact_type' => 'manifest',
            'format_version' => SpellHistoryArtifact::FORMAT_VERSION,
            'canonical_format_version' => SpellCanonicalizer::FORMAT_VERSION,
            'dataset' => $this->datasetKey,
            'snapshots' => [[
                'sequence' => 1,
                'key' => 'spelldata_Live_2002-01-01_00_00_00',
                'observed_at' => '2002-01-01T00:00:00',
                'source_physical_line_count' => 2,
                'health_status' => 'healthy',
                'trusted_for_absence_confirmation' => true,
                'previous_snapshot' => null,
            ]],
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
            'dataset' => $this->datasetKey,
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

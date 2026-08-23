<?php

namespace Tests\Unit\Services\SpellHistory;

use App\Services\SpellHistory\SpellHistoryActivator;
use App\Services\SpellHistory\SpellHistoryInstaller;
use App\Services\SpellHistory\SpellHistoryMutationLock;
use App\Services\SpellHistory\SpellHistoryPackager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CreatesSpellHistoryPackageDataset;
use ZipArchive;

class SpellHistoryInstallerTest extends TestCase
{
    use CreatesSpellHistoryPackageDataset;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not installed.');
        }
        $this->temporaryDirectory = sys_get_temp_dir().'/spell-history-installer-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/source/datasets', 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory)) {
            $this->removePackageTree($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function test_it_installs_valid_local_package_without_activating_and_is_idempotent(): void
    {
        $dataset = str_repeat('a', 64);
        $this->createInstallerDataset($this->temporaryDirectory.'/source', $dataset);
        $release = (new SpellHistoryPackager(new SpellHistoryMutationLock))->create(
            $this->temporaryDirectory.'/source',
            $this->temporaryDirectory.'/release',
        );

        $installer = $this->installer();
        $first = $installer->installFromFile(
            $release['zip_path'],
            $release['sha256'],
            $this->temporaryDirectory.'/installed',
            false,
            ['max_download_bytes' => 10_000_000, 'max_unpacked_bytes' => 10_000_000, 'max_files' => 100],
        );

        $this->assertSame($dataset, $first['dataset']);
        $this->assertFalse($first['reused']);
        $this->assertFalse($first['activated']);
        $this->assertFileDoesNotExist($this->temporaryDirectory.'/installed/CURRENT');
        $this->assertFileExists($first['path'].'/manifest.json');

        $second = $installer->installFromFile(
            $release['zip_path'],
            $release['sha256'],
            $this->temporaryDirectory.'/installed',
            true,
            ['max_download_bytes' => 10_000_000, 'max_unpacked_bytes' => 10_000_000, 'max_files' => 100],
        );

        $this->assertTrue($second['reused']);
        $this->assertTrue($second['activated']);
        $this->assertSame($dataset, trim(file_get_contents($this->temporaryDirectory.'/installed/CURRENT')));
    }

    public function test_it_rejects_a_wrong_local_checksum_before_extracting(): void
    {
        $zipPath = $this->temporaryDirectory.'/bad.zip';
        file_put_contents($zipPath, 'not a zip');

        try {
            $this->installer()->installFromFile(
                $zipPath,
                str_repeat('0', 64),
                $this->temporaryDirectory.'/installed',
                false,
            );
            $this->fail('A package with a wrong checksum should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('failed SHA-256 verification', $exception->getMessage());
        }

        $this->assertSame([], glob($this->temporaryDirectory.'/installed/.installing-*') ?: []);
    }

    public function test_it_rejects_zip_path_traversal_without_writing_outside_staging(): void
    {
        $zipPath = $this->temporaryDirectory.'/traversal.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL));
        $zip->addFromString('../escaped.txt', 'unsafe');
        $zip->addFromString('package.json', '{}');
        $zip->addFromString('dataset/'.str_repeat('b', 64).'/manifest.json', '{}');
        $zip->addFromString('dataset/'.str_repeat('b', 64).'/COMPLETE.json', '{}');
        $this->assertTrue($zip->close());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe path');
        try {
            $this->installer()->installFromFile(
                $zipPath,
                hash_file('sha256', $zipPath),
                $this->temporaryDirectory.'/installed',
                false,
            );
        } finally {
            $this->assertFileDoesNotExist($this->temporaryDirectory.'/escaped.txt');
        }
    }

    private function installer(): SpellHistoryInstaller
    {
        return new SpellHistoryInstaller(new SpellHistoryActivator, new SpellHistoryMutationLock);
    }

    private function createInstallerDataset(string $artifactRoot, string $dataset): void
    {
        $fixture = $this->createPackageDataset($artifactRoot, $dataset);
        $spellPath = $fixture['dataset_root'].'/'.$fixture['spell_relative'];
        $spell = json_decode(file_get_contents($spellPath), true, 64, JSON_THROW_ON_ERROR);
        $spell['revisions'][0]['type'] = 'first_observed';
        $spell['revisions'][0]['groups'] = [];
        $spellJson = $this->packageJson($spell);
        file_put_contents($spellPath, $spellJson);

        $manifestPath = $fixture['dataset_root'].'/manifest.json';
        $manifest = json_decode(file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $manifestJson = $this->packageJson($manifest);
            $bytes = strlen($manifestJson) + strlen($spellJson);
            if ($manifest['stats']['artifact_bytes'] === $bytes) {
                break;
            }
            $manifest['stats']['artifact_bytes'] = $bytes;
        }
        $manifestJson = $this->packageJson($manifest);
        file_put_contents($manifestPath, $manifestJson);
        file_put_contents($fixture['dataset_root'].'/COMPLETE.json', $this->packageJson([
            'schema' => $manifest['schema'],
            'artifact_type' => 'completion',
            'format_version' => $manifest['format_version'],
            'canonical_format_version' => $manifest['canonical_format_version'],
            'dataset' => $dataset,
            'manifest_sha256' => hash('sha256', $manifestJson),
            'manifest_bytes' => strlen($manifestJson),
            'spell_count' => 1,
            'revision_count' => 1,
        ]));
    }
}

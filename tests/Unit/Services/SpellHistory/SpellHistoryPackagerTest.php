<?php

namespace Tests\Unit\Services\SpellHistory;

use App\Services\SpellHistory\SpellHistoryMutationLock;
use App\Services\SpellHistory\SpellHistoryPackager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CreatesSpellHistoryPackageDataset;
use ZipArchive;

class SpellHistoryPackagerTest extends TestCase
{
    use CreatesSpellHistoryPackageDataset;

    private string $temporaryDirectory;

    private string $artifactDirectory;

    private string $outputDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not installed.');
        }
        $this->temporaryDirectory = sys_get_temp_dir().'/spell-history-packager-'.bin2hex(random_bytes(8));
        $this->artifactDirectory = $this->temporaryDirectory.'/artifacts';
        $this->outputDirectory = $this->temporaryDirectory.'/release';
        mkdir($this->artifactDirectory.'/datasets', 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory)) {
            $this->removePackageTree($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function test_it_packages_only_current_dataset_and_emits_verified_release_assets(): void
    {
        $current = str_repeat('a', 64);
        $partial = str_repeat('b', 64);
        $fixture = $this->createPackageDataset($this->artifactDirectory, $current);
        mkdir($this->artifactDirectory.'/datasets/'.$partial);
        file_put_contents($this->artifactDirectory.'/datasets/'.$partial.'/manifest.json', '{}');

        $result = $this->packager()->create($this->artifactDirectory, $this->outputDirectory);

        $this->assertSame($current, $result['dataset']);
        $this->assertSame(
            'modern-allaclone-spell-history-f4-c3-2025-12-03-aaaaaaaaaaaa.zip',
            $result['archive_name'],
        );
        $this->assertSame(hash_file('sha256', $result['zip_path']), $result['sha256']);
        $this->assertSame(
            "{$result['sha256']}  {$result['archive_name']}\n",
            file_get_contents($result['checksum_path']),
        );

        $descriptor = json_decode(file_get_contents($result['descriptor_path']), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(SpellHistoryPackager::PACKAGE_SCHEMA, $descriptor['schema']);
        $this->assertSame(SpellHistoryPackager::PACKAGE_VERSION, $descriptor['package_version']);
        $this->assertSame($current, $descriptor['dataset']);
        $this->assertSame('2025-12-03T05:26:16', $descriptor['first_capture']);
        $this->assertSame('2025-12-03T05:26:16', $descriptor['latest_capture']);
        $this->assertSame(3, $descriptor['stats']['file_count']);
        $this->assertSame(
            $fixture['manifest_bytes'] + $fixture['spell_bytes'],
            $descriptor['stats']['artifact_bytes'],
        );
        $this->assertSame(
            $descriptor['stats']['artifact_bytes'] + $fixture['completion_bytes'],
            $descriptor['stats']['unpacked_bytes'],
        );
        $this->assertSame($result['sha256'], $descriptor['archive']['sha256']);
        $this->assertSame($result['archive_bytes'], $descriptor['archive']['bytes']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['zip_path'], ZipArchive::RDONLY));
        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entries[] = $zip->getNameIndex($index);
            }
            sort($entries, SORT_STRING);
            $expected = [
                'package.json',
                "dataset/{$current}/COMPLETE.json",
                "dataset/{$current}/manifest.json",
                "dataset/{$current}/{$fixture['spell_relative']}",
            ];
            sort($expected, SORT_STRING);
            $this->assertSame($expected, $entries);
            $this->assertStringNotContainsString($partial, implode("\n", $entries));

            $package = json_decode($zip->getFromName('package.json'), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame($current, $package['dataset']);
            $this->assertNull($package['archive']['sha256']);
            $this->assertNull($package['archive']['bytes']);
        } finally {
            $zip->close();
        }
    }

    public function test_it_rejects_unexpected_dataset_content_without_publishing_partial_assets(): void
    {
        $current = str_repeat('c', 64);
        $fixture = $this->createPackageDataset($this->artifactDirectory, $current);
        file_put_contents($fixture['dataset_root'].'/unexpected.txt', 'unsafe');

        try {
            $this->packager()->create($this->artifactDirectory, $this->outputDirectory);
            $this->fail('Unexpected dataset content should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('missing or unexpected entries', $exception->getMessage());
        }

        $this->assertSame([], glob($this->outputDirectory.'/*') ?: []);
    }

    public function test_it_rejects_symbolic_linked_spell_artifacts(): void
    {
        $current = str_repeat('d', 64);
        $fixture = $this->createPackageDataset($this->artifactDirectory, $current);
        $spellPath = $fixture['dataset_root'].'/'.$fixture['spell_relative'];
        $outside = $this->temporaryDirectory.'/outside.json';
        file_put_contents($outside, file_get_contents($spellPath));
        unlink($spellPath);
        if (! @symlink($outside, $spellPath)) {
            $this->markTestSkipped('Symbolic links are unavailable in this environment.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing or unsafe spell history artifact');
        $this->packager()->create($this->artifactDirectory, $this->outputDirectory);
    }

    public function test_it_refuses_to_overwrite_an_existing_release_asset_set(): void
    {
        $current = str_repeat('e', 64);
        $this->createPackageDataset($this->artifactDirectory, $current);
        $this->packager()->create($this->artifactDirectory, $this->outputDirectory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to overwrite an existing release asset');

        $this->packager()->create($this->artifactDirectory, $this->outputDirectory);
    }

    private function packager(): SpellHistoryPackager
    {
        return new SpellHistoryPackager(new SpellHistoryMutationLock);
    }
}

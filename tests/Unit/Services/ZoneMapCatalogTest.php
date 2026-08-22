<?php

namespace Tests\Unit\Services;

use App\Services\ZoneMapCatalog;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

class ZoneMapCatalogTest extends TestCase
{
    private string $publicRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicRoot = storage_path('framework/testing/map-catalog-'.bin2hex(random_bytes(6)));
        mkdir($this->publicRoot.DIRECTORY_SEPARATOR.'maps'.DIRECTORY_SEPARATOR.'zones', 0777, true);
        $this->app->make(UrlGenerator::class)->forceRootUrl('https://example.test/magelo');
    }

    protected function tearDown(): void
    {
        $this->app->make(UrlGenerator::class)->forceRootUrl(null);
        $this->app->make(Filesystem::class)->deleteDirectory($this->publicRoot);

        parent::tearDown();
    }

    public function test_it_returns_only_normalized_allowlisted_map_metadata(): void
    {
        file_put_contents($this->assetPath('qeynos'), '{}');
        $this->writeManifest([
            'qeynos' => [
                'path' => 'maps/zones/qeynos.abcdef123456.eqmap',
                'sha256' => $this->sha256(),
                'bytes' => 2,
                'segments' => 3294,
                'points' => 3,
                'bounds' => [
                    'min_x' => -999,
                    'min_y' => -597.8,
                    'min_z' => -98,
                    'max_x' => 699.5,
                    'max_y' => 599,
                    'max_z' => 28,
                ],
            ],
        ]);

        $map = $this->catalog()->find('qeynos');

        $this->assertSame(true, $map['available']);
        $this->assertSame('https://example.test/magelo/maps/zones/qeynos.abcdef123456.eqmap', $map['url']);
        $this->assertSame(3294, $map['segments']);
        $this->assertSame(3, $map['points']);
        $this->assertSame(-999.0, $map['bounds']['min_x']);
        $this->assertSame(699.5, $map['bounds']['max_x']);
    }

    public function test_it_rejects_traversal_mismatched_urls_and_invalid_bounds(): void
    {
        foreach (['qeynos', 'freeport', 'felwithe', 'misty'] as $zone) {
            file_put_contents($this->assetPath($zone), '{}');
        }

        $validBounds = [
            'min_x' => -10,
            'min_y' => -10,
            'min_z' => -10,
            'max_x' => 10,
            'max_y' => 10,
            'max_z' => 10,
        ];

        $this->writeManifest([
            '../qeynos' => [
                'path' => 'maps/zones/qeynos.abcdef123456.eqmap',
                'sha256' => $this->sha256(),
                'bytes' => 2,
                'segments' => 1,
                'points' => 0,
                'bounds' => $validBounds,
            ],
            'qeynos' => [
                'path' => 'maps/zones/../qeynos.abcdef123456.eqmap',
                'sha256' => $this->sha256(),
                'bytes' => 2,
                'segments' => 1,
                'points' => 0,
                'bounds' => $validBounds,
            ],
            'freeport' => [
                'path' => 'maps/zones/felwithe.abcdef123456.eqmap',
                'sha256' => $this->sha256(),
                'bytes' => 2,
                'segments' => 1,
                'points' => 0,
                'bounds' => $validBounds,
            ],
            'felwithe' => [
                'path' => 'maps/zones/felwithe.abcdef123456.eqmap',
                'sha256' => $this->sha256(),
                'bytes' => 2,
                'segments' => 1,
                'points' => 0,
                'bounds' => [...$validBounds, 'min_x' => 20],
            ],
            'misty' => [
                'path' => 'maps/zones/misty.abcdef123456.eqmap',
                'sha256' => str_repeat('b', 64),
                'bytes' => 2,
                'segments' => 1,
                'points' => 0,
                'bounds' => $validBounds,
            ],
        ]);

        $catalog = $this->catalog();

        $this->assertNull($catalog->find('../qeynos'));
        $this->assertNull($catalog->find('qeynos'));
        $this->assertNull($catalog->find('freeport'));
        $this->assertNull($catalog->find('felwithe'));
        $this->assertNull($catalog->find('misty'));
    }

    public function test_it_fails_closed_for_invalid_manifests_and_missing_assets(): void
    {
        file_put_contents($this->manifestPath(), '{not-json');
        $this->assertNull($this->catalog()->find('qeynos'));

        file_put_contents($this->manifestPath(), json_encode([
            'version' => 2,
            'format' => 'EQM1',
            'zones' => [],
        ], JSON_THROW_ON_ERROR));
        $this->assertNull($this->catalog()->find('qeynos'));

        $this->writeManifest([
            'qeynos' => [
                'path' => 'maps/zones/qeynos.abcdef123456.eqmap',
                'sha256' => $this->sha256(),
                'bytes' => 2,
                'segments' => 1,
                'points' => 0,
                'bounds' => [
                    'min_x' => -1,
                    'min_y' => -1,
                    'min_z' => -1,
                    'max_x' => 1,
                    'max_y' => 1,
                    'max_z' => 1,
                ],
            ],
        ]);
        $this->assertNull($this->catalog()->find('qeynos'));
    }

    private function catalog(): ZoneMapCatalog
    {
        return new ZoneMapCatalog(
            $this->app->make(UrlGenerator::class),
            $this->manifestPath(),
            $this->publicRoot,
        );
    }

    private function writeManifest(array $zones): void
    {
        file_put_contents($this->manifestPath(), json_encode([
            'version' => 1,
            'format' => 'EQM1',
            'attribution' => [],
            'zones' => $zones,
        ], JSON_THROW_ON_ERROR));
    }

    private function manifestPath(): string
    {
        return $this->publicRoot.DIRECTORY_SEPARATOR.'maps'.DIRECTORY_SEPARATOR.'manifest.json';
    }

    private function assetPath(string $zone): string
    {
        return $this->publicRoot.DIRECTORY_SEPARATOR.'maps'.DIRECTORY_SEPARATOR.'zones'.DIRECTORY_SEPARATOR.$zone.'.abcdef123456.eqmap';
    }

    private function sha256(): string
    {
        return 'abcdef123456'.str_repeat('a', 52);
    }
}

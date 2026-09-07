<?php

namespace Tests\Feature\Services;

use App\Services\ItemHistory\ItemHistoryActivator;
use App\Services\ItemHistory\ItemHistoryDataset;
use App\Services\ItemHistory\ItemHistoryInstaller;
use App\Services\ItemHistory\ItemHistoryMutationLock;
use App\Services\ItemHistory\ItemHistoryPackager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CreatesItemHistoryPackageDataset;
use Tests\TestCase;
use ZipArchive;

class ItemHistoryInstallerReleaseTest extends TestCase
{
    use CreatesItemHistoryPackageDataset;

    private const REPOSITORY = 'example-owner/example-repository';

    private const TAG = 'item-history-data-v2-test';

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not installed.');
        }

        Http::preventStrayRequests();
        $this->temporaryDirectory = sys_get_temp_dir().'/item-history-release-install-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/source', 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory)) {
            $this->removeItemHistoryPackageTree($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function test_it_installs_the_exact_tag_from_the_exact_repository_and_activates_it(): void
    {
        $fixture = $this->releaseFixture();
        $this->fakeRelease($fixture);

        $result = $this->installer()->installFromRelease(
            self::TAG,
            self::REPOSITORY,
            $fixture['package']['sha256'],
            $this->installedRoot(),
            true,
            $this->smallLimits(),
        );

        $this->assertSame($fixture['package']['dataset'], $result['dataset']);
        $this->assertFalse($result['reused']);
        $this->assertTrue($result['activated']);
        $this->assertSame(
            $fixture['package']['dataset'],
            trim(file_get_contents($this->installedRoot().'/CURRENT')),
        );
        $this->assertFileExists($result['path'].'/manifest.json');
        $this->assertFileExists($result['path'].'/COMPLETE.json');
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === $fixture['api_url']);
        Http::assertSent(fn (Request $request): bool => $request->url() === $fixture['descriptor_url']);
        Http::assertSent(fn (Request $request): bool => $request->url() === $fixture['archive_url']);
        $this->assertNoWorkDirectories();
    }

    #[DataProvider('duplicateAssetCases')]
    public function test_it_rejects_duplicate_release_assets(
        string $asset,
        string $expectedMessage,
        int $expectedRequests,
    ): void {
        $fixture = $this->releaseFixture();
        $metadata = $fixture['metadata'];
        $metadata['assets'][] = $fixture[$asset];
        $this->fakeRelease($fixture, $metadata);

        $this->assertReleaseFailure(
            $fixture['package']['sha256'],
            $expectedMessage,
        );

        Http::assertSentCount($expectedRequests);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function duplicateAssetCases(): iterable
    {
        yield 'descriptor' => [
            'descriptor_asset',
            'must contain exactly one '.ItemHistoryDataset::RELEASE_DESCRIPTOR.' asset',
            1,
        ];
        yield 'archive' => [
            'archive_asset',
            'must contain exactly one modern-allaclone-item-history-',
            2,
        ];
    }

    #[DataProvider('downloadDigestMismatchCases')]
    public function test_it_rejects_downloaded_bytes_that_do_not_match_github_digests(
        string $corruptAsset,
        int $expectedRequests,
    ): void {
        $fixture = $this->releaseFixture();
        $descriptorBody = $fixture['descriptor_body'];
        $archiveBody = $fixture['archive_body'];
        if ($corruptAsset === 'descriptor') {
            $descriptorBody[0] = '[';
        } else {
            $archiveBody[0] = $archiveBody[0] === 'x' ? 'y' : 'x';
        }
        $this->fakeRelease($fixture, descriptorBody: $descriptorBody, archiveBody: $archiveBody);

        $this->assertReleaseFailure(
            $fixture['package']['sha256'],
            'Downloaded GitHub asset failed SHA-256 verification.',
        );

        Http::assertSentCount($expectedRequests);
    }

    /** @return iterable<string, array{string, int}> */
    public static function downloadDigestMismatchCases(): iterable
    {
        yield 'descriptor bytes' => ['descriptor', 2];
        yield 'archive bytes' => ['archive', 3];
    }

    public function test_it_rejects_a_github_archive_digest_that_disagrees_with_the_descriptor(): void
    {
        $fixture = $this->releaseFixture();
        $metadata = $this->replaceAsset($fixture['metadata'], $fixture['package']['archive_name'], [
            ...$fixture['archive_asset'],
            'digest' => 'sha256:'.str_repeat('0', 64),
        ]);
        $this->fakeRelease($fixture, $metadata);

        $this->assertReleaseFailure(
            $fixture['package']['sha256'],
            'The GitHub archive digest does not match the release descriptor.',
        );

        Http::assertSentCount(2);
    }

    public function test_it_rejects_a_release_that_does_not_match_the_independent_pin(): void
    {
        $fixture = $this->releaseFixture();
        $this->fakeRelease($fixture);

        $this->assertReleaseFailure(
            str_repeat('0', 64),
            'The GitHub archive digest does not match the pinned release checksum.',
        );

        Http::assertSentCount(2);
    }

    public function test_it_rejects_release_metadata_for_a_different_tag(): void
    {
        $fixture = $this->releaseFixture();
        $metadata = [
            ...$fixture['metadata'],
            'tag_name' => 'item-history-data-v2-other',
        ];
        $this->fakeRelease($fixture, $metadata);

        $this->assertReleaseFailure(
            $fixture['package']['sha256'],
            'GitHub returned a release for a different tag.',
        );

        Http::assertSentCount(1);
    }

    public function test_it_rejects_release_assets_over_the_descriptor_size_limit(): void
    {
        $fixture = $this->releaseFixture();
        $metadata = $this->replaceAsset($fixture['metadata'], ItemHistoryDataset::RELEASE_DESCRIPTOR, [
            ...$fixture['descriptor_asset'],
            'size' => ItemHistoryDataset::MAX_DESCRIPTOR_BYTES + 1,
        ]);
        $this->fakeRelease($fixture, $metadata);

        $this->assertReleaseFailure(
            $fixture['package']['sha256'],
            'GitHub release asset metadata is invalid or exceeds the configured limit.',
        );

        Http::assertSentCount(1);
    }

    public function test_it_rejects_release_asset_urls_outside_the_exact_repository(): void
    {
        $fixture = $this->releaseFixture();
        $metadata = $this->replaceAsset($fixture['metadata'], $fixture['package']['archive_name'], [
            ...$fixture['archive_asset'],
            'browser_download_url' => 'https://github.com/other-owner/other-repository/releases/download/'.
                self::TAG.'/'.$fixture['package']['archive_name'],
        ]);
        $this->fakeRelease($fixture, $metadata);

        $this->assertReleaseFailure(
            $fixture['package']['sha256'],
            'GitHub release asset URL is outside the configured repository.',
        );

        Http::assertSentCount(2);
    }

    /**
     * @return array{
     *     package: array<string, mixed>,
     *     metadata: array<string, mixed>,
     *     descriptor_asset: array<string, mixed>,
     *     archive_asset: array<string, mixed>,
     *     descriptor_body: string,
     *     archive_body: string,
     *     api_url: string,
     *     descriptor_url: string,
     *     archive_url: string
     * }
     */
    private function releaseFixture(): array
    {
        $this->createLegacyItemHistoryArtifacts($this->temporaryDirectory.'/source', [13_405, 20_542]);
        $package = (new ItemHistoryPackager(new ItemHistoryMutationLock))->create(
            $this->temporaryDirectory.'/source',
            $this->temporaryDirectory.'/release',
        );
        $descriptorBody = file_get_contents($package['descriptor_path']);
        $archiveBody = file_get_contents($package['zip_path']);
        $this->assertIsString($descriptorBody);
        $this->assertIsString($archiveBody);

        $descriptorUrl = $this->assetUrl(ItemHistoryDataset::RELEASE_DESCRIPTOR);
        $archiveUrl = $this->assetUrl($package['archive_name']);
        $descriptorAsset = [
            'name' => ItemHistoryDataset::RELEASE_DESCRIPTOR,
            'digest' => 'sha256:'.hash('sha256', $descriptorBody),
            'size' => strlen($descriptorBody),
            'browser_download_url' => $descriptorUrl,
        ];
        $archiveAsset = [
            'name' => $package['archive_name'],
            'digest' => 'sha256:'.$package['sha256'],
            'size' => strlen($archiveBody),
            'browser_download_url' => $archiveUrl,
        ];

        return [
            'package' => $package,
            'metadata' => [
                'tag_name' => self::TAG,
                'assets' => [$descriptorAsset, $archiveAsset],
            ],
            'descriptor_asset' => $descriptorAsset,
            'archive_asset' => $archiveAsset,
            'descriptor_body' => $descriptorBody,
            'archive_body' => $archiveBody,
            'api_url' => 'https://api.github.com/repos/'.self::REPOSITORY.'/releases/tags/'.rawurlencode(self::TAG),
            'descriptor_url' => $descriptorUrl,
            'archive_url' => $archiveUrl,
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function fakeRelease(
        array $fixture,
        ?array $metadata = null,
        ?string $descriptorBody = null,
        ?string $archiveBody = null,
    ): void {
        $descriptorBody ??= $fixture['descriptor_body'];
        $archiveBody ??= $fixture['archive_body'];
        Http::fake([
            $fixture['api_url'] => Http::response($metadata ?? $fixture['metadata'], 200),
            $fixture['descriptor_url'] => Http::response($descriptorBody, 200, [
                'Content-Length' => (string) strlen($descriptorBody),
            ]),
            $fixture['archive_url'] => Http::response($archiveBody, 200, [
                'Content-Length' => (string) strlen($archiveBody),
            ]),
        ]);
    }

    /**
     * @param  array<string, int>  $limits
     */
    private function assertReleaseFailure(
        string $pinnedSha256,
        string $expectedMessage,
        array $limits = [],
    ): void {
        try {
            $this->installer()->installFromRelease(
                self::TAG,
                self::REPOSITORY,
                $pinnedSha256,
                $this->installedRoot(),
                true,
                $limits === [] ? $this->smallLimits() : $limits,
            );
            $this->fail('An untrusted GitHub release should not be installed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->installedRoot().'/CURRENT');
        $this->assertFileDoesNotExist($this->installedRoot().'/.CURRENT.bak');
        $this->assertSame([], glob($this->installedRoot().'/datasets/*') ?: []);
        $this->assertNoWorkDirectories();
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $replacement
     * @return array<string, mixed>
     */
    private function replaceAsset(array $release, string $name, array $replacement): array
    {
        foreach ($release['assets'] as $index => $asset) {
            if (($asset['name'] ?? null) === $name) {
                $release['assets'][$index] = $replacement;

                return $release;
            }
        }

        $this->fail("Release fixture has no {$name} asset.");
    }

    private function assetUrl(string $name): string
    {
        return 'https://github.com/'.self::REPOSITORY.'/releases/download/'.self::TAG.'/'.$name;
    }

    /** @return array<string, int> */
    private function smallLimits(): array
    {
        return [
            'max_download_bytes' => 10_000_000,
            'max_unpacked_bytes' => 10_000_000,
            'max_files' => 100,
            'connect_timeout' => 5,
            'download_timeout' => 30,
        ];
    }

    private function installedRoot(): string
    {
        return $this->temporaryDirectory.'/installed';
    }

    private function installer(): ItemHistoryInstaller
    {
        return new ItemHistoryInstaller(new ItemHistoryActivator, new ItemHistoryMutationLock);
    }

    private function assertNoWorkDirectories(): void
    {
        $this->assertSame([], glob($this->installedRoot().'/.download-*') ?: []);
        $this->assertSame([], glob($this->installedRoot().'/.installing-*') ?: []);
    }
}

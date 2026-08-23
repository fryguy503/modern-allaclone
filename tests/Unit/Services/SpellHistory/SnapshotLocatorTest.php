<?php

namespace Tests\Unit\Services\SpellHistory;

use App\Services\SpellHistory\SnapshotLocator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SnapshotLocatorTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().'/spell-history-locator-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_it_orders_snapshot_filenames_chronologically_and_ignores_other_files(): void
    {
        file_put_contents($this->temporaryDirectory.'/spelldata_Live_2003-01-02_12_00_00.txt', "id,name\n1,Later\n");
        file_put_contents($this->temporaryDirectory.'/spelldata_Live_2002-12-31_23_59_59.txt', "id,name\n1,Earlier\n");
        file_put_contents($this->temporaryDirectory.'/README.txt', 'not a snapshot');

        $locator = new SnapshotLocator;
        $snapshots = $locator->locate($this->temporaryDirectory);

        $this->assertSame(
            ['spelldata_Live_2002-12-31_23_59_59', 'spelldata_Live_2003-01-02_12_00_00'],
            array_column($snapshots, 'key'),
        );
        $this->assertSame('2002-12-31T23:59:59', $snapshots[0]->observedAtIso());
        $this->assertSame(['README.txt'], $locator->ignoredTextFiles());
    }

    public function test_it_rejects_a_snapshot_filename_with_an_impossible_date(): void
    {
        file_put_contents($this->temporaryDirectory.'/spelldata_Live_2003-02-30_12_00_00.txt', "id,name\n1,Bad\n");

        $this->expectException(InvalidArgumentException::class);
        (new SnapshotLocator)->locate($this->temporaryDirectory);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}

<?php

namespace Tests\Unit\Services\SpellHistory;

use App\Services\SpellHistory\SpellHistoryActivator;
use App\Services\SpellHistory\SpellHistoryMutationLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SpellHistoryActivatorTest extends TestCase
{
    private string $temporaryDirectory;

    private string $artifactDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().'/spell-history-activator-'.bin2hex(random_bytes(8));
        $this->artifactDirectory = $this->temporaryDirectory.'/artifacts';
        mkdir($this->artifactDirectory.'/datasets', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_activation_is_atomic_and_reports_the_previous_dataset(): void
    {
        $first = str_repeat('a', 64);
        $second = str_repeat('b', 64);
        $this->createDataset($first);
        $this->createDataset($second);

        $activator = new SpellHistoryActivator;

        $this->assertNull($activator->activate($this->artifactDirectory, $first));
        $this->assertSame($first, trim((string) file_get_contents($this->artifactDirectory.'/CURRENT')));
        $this->assertSame($first, $activator->activate($this->artifactDirectory, $second));
        $this->assertSame($second, trim((string) file_get_contents($this->artifactDirectory.'/CURRENT')));
        $this->assertFileDoesNotExist($this->artifactDirectory.'/.CURRENT.bak');
    }

    public function test_activation_recovers_a_valid_backup_before_switching(): void
    {
        $first = str_repeat('a', 64);
        $second = str_repeat('b', 64);
        $this->createDataset($first);
        $this->createDataset($second);
        file_put_contents($this->artifactDirectory.'/.CURRENT.bak', $first."\n");

        $previous = (new SpellHistoryActivator)->activate($this->artifactDirectory, $second);

        $this->assertSame($first, $previous);
        $this->assertSame($second, trim((string) file_get_contents($this->artifactDirectory.'/CURRENT')));
        $this->assertFileDoesNotExist($this->artifactDirectory.'/.CURRENT.bak');
    }

    public function test_activation_refuses_a_missing_dataset(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing or unsafe');

        (new SpellHistoryActivator)->activate($this->artifactDirectory, str_repeat('a', 64));
    }

    public function test_mutation_lock_refuses_an_unsafe_lock_entry(): void
    {
        mkdir($this->artifactDirectory.'/.prune.lock');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mutation lock must be a regular file');

        (new SpellHistoryMutationLock)->exclusive($this->artifactDirectory, static fn (): null => null);
    }

    private function createDataset(string $datasetKey): void
    {
        $directory = $this->artifactDirectory.'/datasets/'.$datasetKey;
        mkdir($directory);
        file_put_contents($directory.'/manifest.json', '{}');
        file_put_contents($directory.'/COMPLETE.json', '{}');
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

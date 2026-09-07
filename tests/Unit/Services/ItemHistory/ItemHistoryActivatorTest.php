<?php

namespace Tests\Unit\Services\ItemHistory;

use App\Services\ItemHistory\ItemHistoryActivator;
use App\Services\ItemHistory\ItemHistoryMutationLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ItemHistoryActivatorTest extends TestCase
{
    private string $temporaryDirectory;

    private string $artifactDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().'/item-history-activator-'.bin2hex(random_bytes(8));
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

        $activator = new ItemHistoryActivator;

        $this->assertNull($activator->activate($this->artifactDirectory, $first));
        $this->assertSame($first."\n", file_get_contents($this->artifactDirectory.'/CURRENT'));
        $this->assertSame($first, $activator->activate($this->artifactDirectory, $second));
        $this->assertSame($second."\n", file_get_contents($this->artifactDirectory.'/CURRENT'));
        $this->assertFileExists($this->artifactDirectory.'/.activation.lock');
        $this->assertFileDoesNotExist($this->artifactDirectory.'/.CURRENT.bak');
    }

    public function test_activation_recovers_a_valid_backup_before_switching(): void
    {
        $first = str_repeat('a', 64);
        $second = str_repeat('b', 64);
        $this->createDataset($first);
        $this->createDataset($second);
        file_put_contents($this->artifactDirectory.'/.CURRENT.bak', $first."\n");

        $previous = (new ItemHistoryActivator)->activate($this->artifactDirectory, $second);

        $this->assertSame($first, $previous);
        $this->assertSame($second."\n", file_get_contents($this->artifactDirectory.'/CURRENT'));
        $this->assertFileDoesNotExist($this->artifactDirectory.'/.CURRENT.bak');
    }

    public function test_activation_refuses_missing_or_incomplete_targets_without_changing_current(): void
    {
        $active = str_repeat('a', 64);
        $missing = str_repeat('b', 64);
        $missingManifest = str_repeat('c', 64);
        $missingCompletion = str_repeat('d', 64);
        $unsafeItems = str_repeat('e', 64);
        $this->createDataset($active);
        (new ItemHistoryActivator)->activate($this->artifactDirectory, $active);

        $missingManifestRoot = $this->artifactDirectory.'/datasets/'.$missingManifest;
        mkdir($missingManifestRoot.'/items', 0755, true);
        file_put_contents($missingManifestRoot.'/COMPLETE.json', '{}');

        $missingCompletionRoot = $this->artifactDirectory.'/datasets/'.$missingCompletion;
        mkdir($missingCompletionRoot.'/items', 0755, true);
        file_put_contents($missingCompletionRoot.'/manifest.json', '{}');

        $unsafeItemsRoot = $this->artifactDirectory.'/datasets/'.$unsafeItems;
        mkdir($unsafeItemsRoot, 0755, true);
        file_put_contents($unsafeItemsRoot.'/manifest.json', '{}');
        file_put_contents($unsafeItemsRoot.'/COMPLETE.json', '{}');
        file_put_contents($unsafeItemsRoot.'/items', 'not-a-directory');

        foreach ([$missing, $missingManifest, $missingCompletion, $unsafeItems] as $datasetKey) {
            try {
                (new ItemHistoryActivator)->activate($this->artifactDirectory, $datasetKey);
                $this->fail("Unsafe dataset {$datasetKey} was activated.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame($active."\n", file_get_contents($this->artifactDirectory.'/CURRENT'));
        }
    }

    public function test_activation_refuses_an_invalid_dataset_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid item history dataset key');

        (new ItemHistoryActivator)->activate($this->artifactDirectory, str_repeat('A', 64));
    }

    public function test_mutation_lock_runs_operations_and_refuses_an_unsafe_lock_entry(): void
    {
        $lock = new ItemHistoryMutationLock;
        $this->assertSame('shared', $lock->shared($this->artifactDirectory, static fn (): string => 'shared'));
        $this->assertSame('exclusive', $lock->exclusive(
            $this->artifactDirectory,
            static fn (): string => 'exclusive',
        ));

        unlink($this->artifactDirectory.'/.prune.lock');
        mkdir($this->artifactDirectory.'/.prune.lock');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mutation lock must be a regular file');
        $lock->exclusive($this->artifactDirectory, static fn (): null => null);
    }

    private function createDataset(string $datasetKey): void
    {
        $directory = $this->artifactDirectory.'/datasets/'.$datasetKey;
        mkdir($directory.'/items', 0755, true);
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

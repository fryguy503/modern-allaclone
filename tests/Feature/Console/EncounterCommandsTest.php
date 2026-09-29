<?php

namespace Tests\Feature\Console;

use App\Services\EncounterJournal\EncounterCatalog;
use Illuminate\Console\Command;
use Tests\TestCase;

final class EncounterCommandsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/encounter-commands-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0755, true);
        config()->set('everquest.encounter_journal.path', $this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_scaffold_creates_valid_hidden_draft_and_refuses_to_clobber_it(): void
    {
        $this->artisan('encounters:make', ['slug' => 'example-boss'])
            ->expectsOutputToContain('Created draft encounter:')
            ->assertExitCode(Command::SUCCESS);

        $catalog = new EncounterCatalog($this->directory);
        $this->assertTrue($catalog->validationReport()['valid']);
        $this->assertNull($catalog->find('example-boss'));
        $this->assertSame('draft', $catalog->find('example-boss', true)['status']);
        $path = $this->directory.'/example-boss.json';
        $original = file_get_contents($path);

        $this->artisan('encounters:make', ['slug' => 'example-boss'])
            ->expectsOutputToContain('already exist')
            ->assertExitCode(Command::FAILURE);
        $this->assertSame($original, file_get_contents($path));
        $this->artisan('encounters:validate')
            ->expectsOutputToContain('Validated 1 encounter documents')
            ->expectsOutputToContain('Source freshness was not checked')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_scaffold_rejects_path_traversal(): void
    {
        $this->artisan('encounters:make', ['slug' => '../outside'])
            ->expectsOutputToContain('lowercase kebab-case')
            ->assertExitCode(Command::INVALID);
        $this->assertSame([], glob($this->directory.'/*'));
    }

    public function test_validate_reports_bad_documents_with_nonzero_exit_code(): void
    {
        file_put_contents($this->directory.'/invalid.json', '{broken json');
        $this->artisan('encounters:validate')
            ->expectsOutputToContain('invalid.json: Invalid JSON')
            ->expectsOutputToContain('Encounter validation failed.')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_validate_rejects_missing_source_root(): void
    {
        $this->artisan('encounters:validate', ['--source-root' => $this->directory.'/missing'])
            ->expectsOutputToContain('Source root does not exist')
            ->assertExitCode(Command::FAILURE);
    }
}

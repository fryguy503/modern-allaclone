<?php

namespace Tests\Feature;

use App\Services\PatchArchive;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Tests\Concerns\CreatesEmptyEqemuSearchSchema;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class PatchHistoryDisabledTest extends TestCase
{
    use CreatesEmptyEqemuSearchSchema;

    public function createApplication()
    {
        putenv('PATCH_HISTORY_ENABLED=false');
        $_ENV['PATCH_HISTORY_ENABLED'] = 'false';
        $_SERVER['PATCH_HISTORY_ENABLED'] = 'false';

        return parent::createApplication();
    }

    public function test_disabled_patch_history_is_not_registered_or_advertised(): void
    {
        $this->assertFalse(config('everquest.patch_history.enable'));
        $this->assertFalse(Route::has('patches.index'));
        $this->assertFalse($this->app->bound(PatchArchive::class));

        foreach (['/patches', '/patches/sources', '/patches/export/json', '/patch', '/patch/export/csv/Source'] as $uri) {
            $this->get($uri)->assertNotFound();
        }

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Patch History')
            ->assertDontSee('/patches', false)
            ->assertDontSee('application/rss+xml', false)
            ->assertDontSee('Search NPCs, items, patches...', false)
            ->assertSee('Search NPCs, items...', false);
    }

    public function test_disabled_global_search_never_resolves_or_returns_patch_suggestions(): void
    {
        $this->useEmptyEqemuSearchDatabase();
        $this->app->bind(PatchArchive::class, function () {
            throw new RuntimeException('The disabled patch archive must not be resolved.');
        });

        $this->getJson('/search/suggest?q=Plane%20of%20Time')
            ->assertOk()
            ->assertExactJson([]);
    }
}

<?php

namespace App\Providers;

use App\Services\ItemHistory\ItemHistoryRepository;
use App\Services\EncounterJournal\EncounterCatalog;
use App\Services\PatchArchive;
use App\Services\SpellHistory\SpellHistoryRepository;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Request-scoped so document edits are picked up without a forever cache,
        // including under long-lived workers.
        $this->app->scoped(EncounterCatalog::class);

        if (config('everquest.patch_history.enable', true)) {
            $this->app->singleton(PatchArchive::class);
        }

        $this->app->singleton(ItemHistoryRepository::class, function (): ItemHistoryRepository {
            return new ItemHistoryRepository(
                (string) config('everquest.item_history.artifact_path'),
            );
        });

        $this->app->singleton(SpellHistoryRepository::class, function (): SpellHistoryRepository {
            return new SpellHistoryRepository(
                (string) config('everquest.spell_history.artifact_path'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('layouts.partials.pagination');
    }
}

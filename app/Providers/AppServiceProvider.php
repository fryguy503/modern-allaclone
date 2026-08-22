<?php

namespace App\Providers;

use App\Services\PatchArchive;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (config('everquest.patch_history.enable', true)) {
            $this->app->singleton(PatchArchive::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('layouts.partials.pagination');
    }
}

<?php

namespace App\Providers;

use App\Display\DisplayStateService;
use App\Posters\PosterSourceManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PosterSourceManager::class);
        $this->app->singleton(DisplayStateService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

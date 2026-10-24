<?php

namespace App\Providers;

use App\Services\Packages\OsvVulnerabilityMatcher;
use App\Services\Packages\VulnerabilityMatcher;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VulnerabilityMatcher::class, OsvVulnerabilityMatcher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // `composer run dev` also runs the scheduler, so the hourly catalog sync happens locally too.
        DevCommands::artisan('schedule:work', 'scheduler');
    }
}

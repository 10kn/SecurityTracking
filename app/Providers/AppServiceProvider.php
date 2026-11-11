<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Notices\ClaudeNoticeClassifier;
use App\Services\Notices\NoticeClassifier;
use App\Services\Packages\GitHubActionsVulnerabilityMatcher;
use App\Services\Packages\OsvVulnerabilityMatcher;
use App\Services\Packages\PackagistVulnerabilityMatcher;
use App\Services\Packages\RubySecVulnerabilityMatcher;
use App\Services\Packages\VulnerabilityMatcher;
use App\Services\Repositories\GitHubRepositoryReader;
use App\Services\Repositories\RepositoryReader;
use App\Services\SystemSettings;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->tag([PackagistVulnerabilityMatcher::class, RubySecVulnerabilityMatcher::class, OsvVulnerabilityMatcher::class, GitHubActionsVulnerabilityMatcher::class], VulnerabilityMatcher::class);

        $this->app->bind(NoticeClassifier::class, ClaudeNoticeClassifier::class);
        $this->app->bind(RepositoryReader::class, GitHubRepositoryReader::class);
        $this->app->singleton(SystemSettings::class);
        $this->app->singleton(Client::class, fn (): Client => new Client(
            apiKey: (string) config('psm.notices.classifier.api_key'),
            requestOptions: ['timeout' => (float) config('psm.notices.classifier.timeout_seconds'), 'maxRetries' => 2],
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Before the scheduler (routes/console.php) and everything else read config/psm.php.
        $this->app->make(SystemSettings::class)->apply();

        // `composer run dev` also runs the scheduler, so the hourly catalog sync happens locally too.
        DevCommands::artisan('schedule:work', 'scheduler');
    }
}

<?php

namespace App\Actions;

use App\Models\GitHubRunnerImage;
use App\Models\SourceHeartbeat;
use App\Services\Catalog\EndoflifeDateClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Refreshes when GitHub removes each hosted runner image, from endoflife.date. Images no longer listed keep their last
 * row. Every attempt is recorded on the source heartbeat, so psm:check-sources alerts the admin when it keeps failing.
 */
class SyncGitHubRunnerImages
{
    public function __construct(private EndoflifeDateClient $endoflife) {}

    /**
     * @throws Throwable The source error, after it was recorded on the heartbeat.
     */
    public function handle(): void
    {
        $heartbeat = SourceHeartbeat::updateOrCreate(['source' => GitHubRunnerImage::SOURCE_KEY], [
            'name' => __('Danh mục runner GitHub Actions'),
            'threshold_minutes' => config('psm.github_actions.runner_images_stale_after_minutes'),
        ]);

        try {
            $images = $this->endoflife->branches(GitHubRunnerImage::ENDOFLIFE_PRODUCT);
        } catch (Throwable $exception) {
            $heartbeat->update([
                'last_failure_at' => now(),
                'last_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 500),
            ]);

            throw $exception;
        }

        DB::transaction(function () use ($images): void {
            foreach ($images as $image) {
                GitHubRunnerImage::updateOrCreate(['name' => strtolower($image->branch)], [
                    'eol_from' => $image->securitySupportUntil?->toDateString(),
                    'is_eol' => $image->isEol,
                    'synced_at' => now(),
                ]);
            }
        });

        $heartbeat->update(['last_success_at' => now(), 'last_error' => null]);
    }

    /**
     * Whether the images were synced successfully within their threshold; a stale catalog leaves findings as they are.
     */
    public static function isCurrent(): bool
    {
        return SourceHeartbeat::firstWhere('source', GitHubRunnerImage::SOURCE_KEY)?->isStale() === false;
    }
}

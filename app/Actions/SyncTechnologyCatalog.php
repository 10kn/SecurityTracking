<?php

namespace App\Actions;

use App\Enums\Technology;
use App\Models\RuntimeBranch;
use App\Models\RuntimeRelease;
use App\Models\SourceHeartbeat;
use App\Services\Catalog\BranchData;
use App\Services\Catalog\EndoflifeDateClient;
use App\Services\Catalog\ReleaseData;
use App\Support\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Refreshes a technology's version catalog: branches and support dates from endoflife.date, releases from the
 * technology's release feed. Every attempt is recorded on the technology's source heartbeat.
 */
class SyncTechnologyCatalog
{
    public function __construct(private EndoflifeDateClient $endoflife) {}

    /**
     * @throws Throwable The source error, after it was recorded on the heartbeat.
     */
    public function handle(Technology $technology): void
    {
        $heartbeat = SourceHeartbeat::updateOrCreate(['source' => $technology->catalogSourceKey()], [
            'name' => __('Danh mục version :technology', ['technology' => $technology->getLabel()]),
            'threshold_minutes' => config('psm.catalog.stale_after_minutes'),
        ]);

        try {
            $branches = $this->endoflife->branches($technology->endoflifeProduct());
            $releases = app($technology->releaseFeed())->releases($technology, $branches);
        } catch (Throwable $exception) {
            $heartbeat->update([
                'last_failure_at' => now(),
                'last_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 500),
            ]);

            throw $exception;
        }

        DB::transaction(fn () => $this->store($technology, $branches, $releases));

        $heartbeat->update(['last_success_at' => now(), 'last_error' => null]);
    }

    /**
     * @param  list<BranchData>  $branches
     * @param  list<ReleaseData>  $releases
     */
    private function store(Technology $technology, array $branches, array $releases): void
    {
        $branchNames = array_map(fn (BranchData $branch): string => $branch->branch, $branches);

        $rows = [];

        foreach ($releases as $release) {
            $branch = Version::branchOf($release->version, $branchNames);

            if ($branch === null) {
                continue;
            }

            $rows[$release->version] = [
                'technology' => $technology->value,
                'branch' => $branch,
                'version' => $release->version,
                'is_security_release' => $release->isSecurityRelease,
                'released_at' => $release->releasedAt?->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            RuntimeRelease::upsert($chunk, ['technology', 'version'], ['branch', 'is_security_release', 'released_at', 'updated_at']);
        }

        foreach ($branches as $branch) {
            $latestRelease = collect($rows)
                ->where('branch', $branch->branch)
                ->pluck('version')
                ->sort(fn (string $a, string $b): int => version_compare($a, $b))
                ->last();

            $latestVersion = collect([$branch->latestVersion, $latestRelease])
                ->filter()
                ->sort(fn (string $a, string $b): int => version_compare($a, $b))
                ->last();

            RuntimeBranch::updateOrCreate(['technology' => $technology, 'branch' => $branch->branch], [
                'latest_version' => $latestVersion,
                'released_at' => $branch->releasedAt,
                'active_support_until' => $branch->activeSupportUntil,
                'security_support_until' => $branch->securitySupportUntil,
                'is_eol' => $branch->isEol,
                'synced_at' => now(),
            ]);
        }
    }
}

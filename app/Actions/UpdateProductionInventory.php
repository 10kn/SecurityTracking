<?php

namespace App\Actions;

use App\Enums\RuntimeSource;
use App\Enums\RuntimeStatus;
use App\Events\DeploymentInventoryChanged;
use App\Models\Deployment;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Saves the runtime versions and lockfiles an admin entered for a service's production environment.
 */
class UpdateProductionInventory
{
    public function __construct(private ReplaceLockfiles $replaceLockfiles) {}

    /**
     * Dispatches DeploymentInventoryChanged only when something changed, so re-saving the same form does not trigger a new evaluation.
     *
     * @param  array<string, string|null>  $runtimeVersions  Raw versions keyed by technology, e.g. "php"; a missing or blank version removes the runtime.
     * @param  list<array{path: string, content: string}>  $uploadedLockfiles
     * @param  list<string>  $removedLockfilePaths
     */
    public function handle(Service $service, array $runtimeVersions, array $uploadedLockfiles = [], array $removedLockfilePaths = []): Deployment
    {
        return DB::transaction(function () use ($service, $runtimeVersions, $uploadedLockfiles, $removedLockfilePaths): Deployment {
            $deployment = $service->productionDeploymentOrCreate();

            $hasRuntimeChanges = $this->syncRuntimes($deployment, $runtimeVersions);
            $hasLockfileChanges = $this->replaceLockfiles->handle($deployment, $uploadedLockfiles, $removedLockfilePaths);

            if ($hasRuntimeChanges || $hasLockfileChanges) {
                $deployment->touch();

                DeploymentInventoryChanged::dispatch($deployment);
            }

            return $deployment;
        });
    }

    /**
     * Technologies left out of $runtimeVersions, or given a blank version, are removed. A changed version resets the
     * runtime to unknown until the evaluator runs again.
     *
     * @param  array<string, string|null>  $runtimeVersions
     */
    private function syncRuntimes(Deployment $deployment, array $runtimeVersions): bool
    {
        $versions = collect($runtimeVersions)
            ->map(fn (?string $rawVersion): string => trim((string) $rawVersion))
            ->filter(fn (string $rawVersion): bool => $rawVersion !== '');

        $hasChanges = $deployment->runtimes()->whereNotIn('technology', $versions->keys())->delete() > 0;

        foreach ($versions as $technology => $rawVersion) {
            if ($deployment->runtimes()->where('technology', $technology)->value('raw_version') === $rawVersion) {
                continue;
            }

            $deployment->runtimes()->updateOrCreate(['technology' => $technology], [
                'raw_version' => $rawVersion,
                'version' => null,
                'branch' => null,
                'source' => RuntimeSource::Manual,
                'status' => RuntimeStatus::Unknown,
                'status_reason' => 'Đang chờ đánh giá',
                'status_context' => null,
                'observed_at' => now(),
                'evaluated_at' => null,
            ]);

            $hasChanges = true;
        }

        return $hasChanges;
    }
}

<?php

namespace App\Actions;

use App\Enums\Ecosystem;
use App\Enums\Environment;
use App\Enums\RuntimeSource;
use App\Enums\RuntimeStatus;
use App\Events\DeploymentInventoryChanged;
use App\Models\Deployment;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Saves the runtime versions and lockfiles an admin entered for a service's production environment.
 */
class UpdateProductionInventory
{
    /**
     * Dispatches DeploymentInventoryChanged only when something changed, so re-saving the same form does not trigger a new evaluation.
     *
     * @param  array<int, string|null>  $runtimeVersions  Raw versions keyed by technology id; a missing or blank version removes the runtime.
     * @param  list<array{name: string, content: string}>  $uploadedLockfiles
     * @param  list<string>  $removedLockfilePaths
     */
    public function handle(Service $service, array $runtimeVersions, array $uploadedLockfiles = [], array $removedLockfilePaths = []): Deployment
    {
        return DB::transaction(function () use ($service, $runtimeVersions, $uploadedLockfiles, $removedLockfilePaths): Deployment {
            $deployment = $service->productionDeployment()->firstOrCreate([
                'environment' => Environment::Production,
                'is_current' => true,
            ]);

            $hasRuntimeChanges = $this->syncRuntimes($deployment, $runtimeVersions);
            $hasLockfileChanges = $this->syncLockfiles($deployment, $uploadedLockfiles, $removedLockfilePaths);

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
     * @param  array<int, string|null>  $runtimeVersions
     */
    private function syncRuntimes(Deployment $deployment, array $runtimeVersions): bool
    {
        $versions = collect($runtimeVersions)
            ->map(fn (?string $rawVersion): string => trim((string) $rawVersion))
            ->filter(fn (string $rawVersion): bool => $rawVersion !== '');

        $hasChanges = $deployment->runtimes()->whereNotIn('technology_id', $versions->keys())->delete() > 0;

        foreach ($versions as $technologyId => $rawVersion) {
            if ($deployment->runtimes()->where('technology_id', $technologyId)->value('raw_version') === $rawVersion) {
                continue;
            }

            $deployment->runtimes()->updateOrCreate(['technology_id' => $technologyId], [
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

    /**
     * An upload replaces the lockfile with the same name. Identical content keeps the existing row untouched.
     *
     * @param  list<array{name: string, content: string}>  $uploadedLockfiles
     * @param  list<string>  $removedLockfilePaths
     */
    private function syncLockfiles(Deployment $deployment, array $uploadedLockfiles, array $removedLockfilePaths): bool
    {
        $hasChanges = $removedLockfilePaths !== []
            && $deployment->lockfiles()->whereIn('path', $removedLockfilePaths)->delete() > 0;

        foreach ($uploadedLockfiles as ['name' => $name, 'content' => $content]) {
            $ecosystem = Ecosystem::forLockfile($name)
                ?? throw new InvalidArgumentException("Unsupported lockfile [{$name}].");

            $contentHash = hash('sha256', $content);

            if ($deployment->lockfiles()->where('path', $name)->value('content_hash') === $contentHash) {
                continue;
            }

            $deployment->lockfiles()->updateOrCreate(['path' => $name], [
                'ecosystem' => $ecosystem,
                'content' => $content,
                'content_hash' => $contentHash,
                'uploaded_at' => now(),
            ]);

            $hasChanges = true;
        }

        return $hasChanges;
    }
}

<?php

namespace App\Actions;

use App\Models\Advisory;
use App\Models\Component;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\SourceHeartbeat;
use App\Services\PackageFindings;
use App\Services\Packages\PackageVersion;
use App\Services\Packages\VulnerabilityMatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Matches the components of deployments against the vulnerability matcher and syncs their package findings. Every
 * attempt is recorded on the matcher's source heartbeat, so psm:check-sources alerts the admin when it keeps failing.
 *
 * Alerts are not sent here: callers run SendFindingAlerts once they finish, so the same advisory across services of
 * a team ends up in one Google Chat card.
 */
class ScanPackages
{
    public function __construct(
        private VulnerabilityMatcher $matcher,
        private SyncFindings $syncFindings,
        private PackageFindings $packageFindings,
    ) {}

    /**
     * When the matcher fails, open package findings stay open (unknown is never ok); only the findings of packages
     * no longer installed are resolved.
     *
     * @param  list<int>  $deploymentIds
     *
     * @throws Throwable The matcher error, after it was recorded on the heartbeat.
     */
    public function handle(array $deploymentIds): void
    {
        $deployments = Deployment::with(['service.project', 'components'])->whereKey($deploymentIds)->get();

        $packages = $deployments
            ->flatMap(fn (Deployment $deployment): Collection => $deployment->components)
            ->map(fn (Component $component): PackageVersion => PackageVersion::of($component))
            ->unique(fn (PackageVersion $package): string => $package->key())
            ->values()
            ->all();

        $matches = $packages === [] ? [] : $this->match($packages, $deployments);

        foreach ($deployments as $deployment) {
            $this->syncFindings->handle($deployment, PackageFindings::RULES, $this->packageFindings->for($deployment->id, $deployment->components, $matches));
        }
    }

    /**
     * @param  list<PackageVersion>  $packages
     * @param  Collection<int, Deployment>  $deployments
     * @return array<string, list<Advisory>>
     *
     * @throws Throwable
     */
    private function match(array $packages, Collection $deployments): array
    {
        $heartbeat = SourceHeartbeat::updateOrCreate(['source' => $this->matcher->sourceKey()], [
            'name' => $this->matcher->sourceName(),
            'threshold_minutes' => config('psm.packages.stale_after_minutes'),
        ]);

        try {
            $matches = $this->matcher->match($packages);
        } catch (Throwable $exception) {
            $heartbeat->update([
                'last_failure_at' => now(),
                'last_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 500),
            ]);

            $this->keepFindingsOpen($deployments);

            throw $exception;
        }

        $heartbeat->update(['last_success_at' => now(), 'last_error' => null]);

        return $matches;
    }

    /**
     * @param  Collection<int, Deployment>  $deployments
     */
    private function keepFindingsOpen(Collection $deployments): void
    {
        foreach ($deployments as $deployment) {
            $installed = $deployment->components->map(fn (Component $component): string => "{$component->ecosystem->value}/{$component->name}")->unique()->all();

            $this->syncFindings->handle(
                $deployment,
                PackageFindings::RULES,
                [],
                fn (Finding $finding): bool => in_array(($finding->context['ecosystem'] ?? '').'/'.($finding->context['package'] ?? ''), $installed, true),
            );
        }
    }
}

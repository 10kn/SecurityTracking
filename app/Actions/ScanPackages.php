<?php

namespace App\Actions;

use App\Enums\Ecosystem;
use App\Models\Advisory;
use App\Models\Component;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\SourceHeartbeat;
use App\Services\PackageFindings;
use App\Services\Packages\PackageVersion;
use App\Services\Packages\VulnerabilityMatcher;
use Closure;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Matches the components of deployments against the vulnerability matchers, each one the packages of its ecosystems,
 * reads the CISA KEV evidence of the advisories found (see MatchKnownExploited) and syncs their package findings. Every attempt is recorded on the matcher's source heartbeat, so psm:check-sources
 * alerts the admin when one keeps failing.
 *
 * Alerts are not sent here: callers run SendFindingAlerts once they finish, so the same advisory across services of
 * a team ends up in one Google Chat card.
 */
class ScanPackages
{
    /**
     * @param  iterable<VulnerabilityMatcher>  $matchers
     */
    public function __construct(
        #[Tag(VulnerabilityMatcher::class)] private iterable $matchers,
        private SyncFindings $syncFindings,
        private PackageFindings $packageFindings,
        private MatchKnownExploited $matchKnownExploited,
    ) {}

    /**
     * A failing matcher does not stop the others. The open findings of its packages stay open (unknown is never ok);
     * only the findings of packages no longer installed are resolved.
     *
     * @param  list<int>  $deploymentIds
     *
     * @throws Throwable The error of the first matcher that failed, after every deployment was synced.
     */
    public function handle(array $deploymentIds): void
    {
        $deployments = Deployment::with(['service.project', 'components'])->whereKey($deploymentIds)->get();

        $packages = $deployments
            ->flatMap(fn (Deployment $deployment): Collection => $deployment->components)
            ->map(fn (Component $component): PackageVersion => PackageVersion::of($component))
            ->unique(fn (PackageVersion $package): string => $package->key())
            ->values();

        $matches = [];
        $failedEcosystems = [];
        $failure = null;

        foreach ($this->matchers as $matcher) {
            $matcherPackages = $packages->filter(fn (PackageVersion $package): bool => in_array($package->ecosystem, $matcher->ecosystems(), true))->values()->all();

            if ($matcherPackages === []) {
                $this->forgetUnusedSource($matcher);

                continue;
            }

            try {
                $matches += $this->match($matcher, $matcherPackages);
            } catch (Throwable $exception) {
                $failedEcosystems = [...$failedEcosystems, ...$matcher->ecosystems()];
                $failure ??= $exception;
            }
        }

        $this->matchKnownExploited->handle(collect($matches)->flatten(1));

        foreach ($deployments as $deployment) {
            $this->syncFindings->handle(
                $deployment,
                PackageFindings::RULES,
                $this->packageFindings->for($deployment->id, $deployment->components, $matches),
                $failedEcosystems === [] ? null : $this->keepOpen($deployment, $failedEcosystems),
            );
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @param  list<PackageVersion>  $packages
     * @return array<string, list<Advisory>>
     *
     * @throws Throwable
     */
    private function match(VulnerabilityMatcher $matcher, array $packages): array
    {
        $heartbeat = SourceHeartbeat::updateOrCreate(['source' => $matcher->sourceKey()], [
            'name' => $matcher->sourceName(),
            'threshold_minutes' => config('psm.packages.stale_after_minutes'),
        ]);

        try {
            $matches = $matcher->match($packages);
        } catch (Throwable $exception) {
            $heartbeat->update([
                'last_failure_at' => now(),
                'last_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 500),
            ]);

            throw $exception;
        }

        $heartbeat->update(['last_success_at' => now(), 'last_error' => null]);

        return $matches;
    }

    /**
     * Drops the heartbeat of a matcher no current deployment has packages for any more: with nothing to match it is
     * never asked, so its last success would only age into a false "over threshold" alert. The next scan that hands
     * it packages records it again. A scan of some deployments only keeps it while others still use the source.
     */
    private function forgetUnusedSource(VulnerabilityMatcher $matcher): void
    {
        $isUsed = Component::whereIn('ecosystem', $matcher->ecosystems())
            ->whereHas('deployment', fn ($query) => $query->where('is_current', true))
            ->exists();

        if (! $isUsed) {
            SourceHeartbeat::where('source', $matcher->sourceKey())->delete();
        }
    }

    /**
     * Open findings of packages still installed, in an ecosystem whose matcher failed.
     *
     * @param  list<Ecosystem>  $failedEcosystems
     * @return Closure(Finding): bool
     */
    private function keepOpen(Deployment $deployment, array $failedEcosystems): Closure
    {
        $failed = array_map(fn (Ecosystem $ecosystem): string => $ecosystem->value, $failedEcosystems);
        $installed = $deployment->components->map(fn (Component $component): string => "{$component->ecosystem->value}/{$component->name}")->unique()->all();

        return fn (Finding $finding): bool => in_array($finding->context['ecosystem'] ?? null, $failed, true)
            && in_array(($finding->context['ecosystem'] ?? '').'/'.($finding->context['package'] ?? ''), $installed, true);
    }
}

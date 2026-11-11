<?php

namespace App\Services;

use App\Enums\Ecosystem;
use App\Enums\FindingRule;
use App\Models\Advisory;
use App\Models\Component;
use App\Models\GitHubActionVersion;
use App\Services\Kev\KnownExploitation;
use App\Services\Packages\PackageVersion;
use App\Support\GitHubAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns matched package versions into findings: one per deployment, package and advisory (handover 5.4: never the
 * version, so upgrading to a release that is still affected keeps the finding). Versions of the same package that
 * share an advisory, as npm can install, make one finding listing every version and every lockfile holding one: a
 * package in both a workspace root lockfile and a nested one is one finding. The severity is the advisory's,
 * raised when CISA KEV lists one of its CVE ids (see KnownExploitation).
 */
class PackageFindings
{
    /**
     * @var list<FindingRule>
     */
    public const array RULES = [FindingRule::DependencyVulnerable];

    public function __construct(private KnownExploitation $knownExploitation) {}

    /**
     * @param  Collection<int, Component>  $components
     * @param  array<string, list<Advisory>>  $matches  Advisories by PackageVersion::key().
     * @return list<FindingData>
     */
    public function for(int $deploymentId, Collection $components, array $matches): array
    {
        $findings = [];
        $actionVersions = GitHubActionVersion::forReferences($components
            ->filter(fn (Component $component): bool => $component->ecosystem === Ecosystem::GitHubActions)
            ->map(fn (Component $component): array => [$component->name, $component->version]));

        foreach ($components->groupBy(fn (Component $component): string => "{$component->ecosystem->value}/{$component->name}") as $subject => $versions) {
            $affected = [];

            foreach ($versions as $component) {
                foreach ($matches[PackageVersion::of($component)->key()] ?? [] as $advisory) {
                    $affected[$advisory->external_id]['advisory'] = $advisory;
                    $affected[$advisory->external_id]['components'][] = $component;
                }
            }

            foreach ($affected as ['advisory' => $advisory, 'components' => $affectedComponents]) {
                $findings[] = $this->finding($deploymentId, (string) $subject, $advisory, collect($affectedComponents), $actionVersions);
            }
        }

        return $findings;
    }

    /**
     * @param  Collection<int, Component>  $components  The versions of one package the advisory affects.
     * @param  Collection<string, GitHubActionVersion>  $actionVersions  What GitHub Actions references point at, by
     *                                                                   "{action}@{ref}".
     */
    private function finding(int $deploymentId, string $subject, Advisory $advisory, Collection $components, Collection $actionVersions): FindingData
    {
        $locale = config('psm.alerts.locale');
        $rule = FindingRule::DependencyVulnerable;

        /** @var Component $component */
        $component = $components->sortBy(fn (Component $component): int => (int) $component->is_dev)->first();
        $isDev = $components->every(fn (Component $component): bool => $component->is_dev);

        $isAction = $component->ecosystem === Ecosystem::GitHubActions;
        $resolvedVersion = fn (Component $component): ?string => $isAction ? $actionVersions->get("{$component->name}@{$component->version}")?->version : $component->version;
        $versions = $components->pluck('version')->unique()->sort(version_compare(...))->values();
        $lockfiles = $components->pluck('lockfile_path')->unique()->sort()->values();
        $fixes = $components
            ->map(fn (Component $component): ?string => ($version = $resolvedVersion($component)) === null ? null : $advisory->fixedVersionFor(
                $component->ecosystem,
                $isAction ? GitHubAction::repository($component->name) : $component->name,
                $version,
            ))
            ->filter()
            ->unique()
            ->sort(version_compare(...))
            ->values();

        $detail = collect([
            $isAction
                ? $components->map(fn (Component $component): string => GitHubActionVersion::describe($component->name, $component->version, $resolvedVersion($component)))->unique()->sort()->join(', ')
                : "{$component->name} {$versions->join(', ')}",
            $fixes->isEmpty() ? __('chưa có bản sửa', [], $locale) : __('nâng lên :version', ['version' => $fixes->join(', ')], $locale),
            $lockfiles->join(', '),
            $isDev ? __('chỉ dùng khi phát triển', [], $locale) : null,
        ])->filter()->join(' · ');

        $context = [
            'ecosystem' => $component->ecosystem->value,
            'package' => $component->name,
            'version' => $versions->join(', '),
            'fixed' => $fixes->isEmpty() ? null : $fixes->join(', '),
            'lockfile' => $lockfiles->first(),
            'lockfiles' => $lockfiles->all(),
            'is_dev' => $isDev,
            'advisory' => $advisory->reference(),
            'advisory_title' => $advisory->title,
            'aliases' => array_values(array_diff($advisory->aliases ?? [], [$advisory->reference()])),
            'source_url' => $advisory->url,
            'detail' => $detail,
        ];

        return $this->knownExploitation->overlay(new FindingData(
            fingerprint: FindingData::fingerprint($rule, $deploymentId, "package:{$subject}", $advisory->external_id),
            rule: $rule,
            severity: $advisory->severity,
            title: Str::limit((string) $rule->title($context, $locale), 250),
            groupKey: "{$rule->value}:{$advisory->external_id}",
            context: $context,
            componentId: $component->id,
            advisoryId: $advisory->id,
        ), $advisory->known_exploited, $advisory->cvss_score);
    }
}

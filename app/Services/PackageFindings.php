<?php

namespace App\Services;

use App\Enums\FindingRule;
use App\Models\Advisory;
use App\Models\Component;
use App\Services\Packages\PackageVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns matched package versions into findings: one per deployment, package and advisory (handover 5.4: never the
 * version, so upgrading to a release that is still affected keeps the finding). Versions of the same package that
 * share an advisory, as npm can install, make one finding listing every version. The severity is the advisory's.
 */
class PackageFindings
{
    /**
     * @var list<FindingRule>
     */
    public const array RULES = [FindingRule::DependencyVulnerable];

    /**
     * @param  Collection<int, Component>  $components
     * @param  array<string, list<Advisory>>  $matches  Advisories by PackageVersion::key().
     * @return list<FindingData>
     */
    public function for(int $deploymentId, Collection $components, array $matches): array
    {
        $findings = [];

        foreach ($components->groupBy(fn (Component $component): string => "{$component->ecosystem->value}/{$component->name}") as $subject => $versions) {
            $affected = [];

            foreach ($versions as $component) {
                foreach ($matches[PackageVersion::of($component)->key()] ?? [] as $advisory) {
                    $affected[$advisory->external_id]['advisory'] = $advisory;
                    $affected[$advisory->external_id]['components'][] = $component;
                }
            }

            foreach ($affected as ['advisory' => $advisory, 'components' => $affectedComponents]) {
                $findings[] = $this->finding($deploymentId, (string) $subject, $advisory, collect($affectedComponents));
            }
        }

        return $findings;
    }

    /**
     * @param  Collection<int, Component>  $components  The versions of one package the advisory affects.
     */
    private function finding(int $deploymentId, string $subject, Advisory $advisory, Collection $components): FindingData
    {
        $locale = config('psm.alerts.locale');
        $rule = FindingRule::DependencyVulnerable;

        /** @var Component $component */
        $component = $components->sortBy(fn (Component $component): int => (int) $component->is_dev)->first();
        $isDev = $components->every(fn (Component $component): bool => $component->is_dev);

        $versions = $components->pluck('version')->unique()->sort(version_compare(...))->values();
        $fixes = $components
            ->map(fn (Component $component): ?string => $advisory->fixedVersionFor($component->ecosystem, $component->name, $component->version))
            ->filter()
            ->unique()
            ->sort(version_compare(...))
            ->values();

        $detail = collect([
            "{$component->name} {$versions->join(', ')}",
            $fixes->isEmpty() ? __('chưa có bản sửa', [], $locale) : __('nâng lên :version', ['version' => $fixes->join(', ')], $locale),
            $component->lockfile_path,
            $isDev ? __('chỉ dùng khi phát triển', [], $locale) : null,
        ])->filter()->join(' · ');

        return new FindingData(
            fingerprint: FindingData::fingerprint($rule, $deploymentId, "package:{$subject}", $advisory->external_id),
            rule: $rule,
            severity: $advisory->severity,
            title: Str::limit(__(':advisory trong :package', ['advisory' => $advisory->reference(), 'package' => $component->name], $locale), 250),
            groupKey: "{$rule->value}:{$advisory->external_id}",
            context: [
                'ecosystem' => $component->ecosystem->value,
                'package' => $component->name,
                'version' => $versions->join(', '),
                'fixed' => $fixes->isEmpty() ? null : $fixes->join(', '),
                'lockfile' => $component->lockfile_path,
                'is_dev' => $isDev,
                'advisory' => $advisory->reference(),
                'advisory_title' => $advisory->title,
                'aliases' => array_values(array_diff($advisory->aliases ?? [], [$advisory->reference()])),
                'source_url' => $advisory->url,
                'detail' => $detail,
            ],
            componentId: $component->id,
            advisoryId: $advisory->id,
        );
    }
}

<?php

namespace App\Services;

use App\Enums\AdvisoryKind;
use App\Enums\FindingRule;
use App\Enums\IncidentScope;
use App\Enums\RuntimeStatus;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentTeamPatch;
use App\Models\Runtime;
use App\Services\Kev\KnownExploitation;
use App\Support\Version;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Turns evaluated runtimes into findings: eol and outdated_security open one; outdated, ok and unknown do not
 * (outdated and unknown only appear in the daily digest).
 *
 * A vendor pre-notice does not raise their severity: it rarely says which branches are affected, so the team hears
 * about it from its own card (see SendPreNoticeAlerts) instead of every older finding of the technology turning
 * Critical. Once the team got a "Đã có bản vá" card for the branch, its "thiếu bản vá" finding asking for that
 * version or an earlier one takes the incident's severity and counts as sent by that card (see CoverPatchedFindings);
 * one asking for a later release is a finding of its own.
 *
 * A "thiếu bản vá" finding lists the fixes vendors confirmed for its branch above the running version, with the CVE ids
 * of the pre-notice that confirmed each (`related_patches`): the release that fixes the branch addresses them. It does
 * not say the service is affected by each of them, as the vendor rarely says which CVE affects which branch. When CISA
 * KEV lists one of them, the finding rises to the KEV severity and takes the CISA due date (see KnownExploitation), to
 * prioritise the upgrade. An incident the vendor said does not affect the branch is left out.
 */
class RuntimeFindings
{
    /**
     * @var list<FindingRule>
     */
    public const array RULES = [FindingRule::RuntimeEol, FindingRule::RuntimeOutdatedSecurity];

    /**
     * The confirmed fixes of the current incidents, by technology, read once per instance.
     *
     * @var array<string, list<array{incident: Incident, patches: array<string, array{version: string, notice: Advisory}>}>>
     */
    private array $confirmedPatches = [];

    public function __construct(private AlertPolicy $policy, private KnownExploitation $knownExploitation) {}

    public function for(Runtime $runtime): ?FindingData
    {
        $rule = match ($runtime->status) {
            RuntimeStatus::Eol => FindingRule::RuntimeEol,
            RuntimeStatus::OutdatedSecurity => FindingRule::RuntimeOutdatedSecurity,
            default => null,
        };

        if ($rule === null || $runtime->branch === null) {
            return null;
        }

        $technology = $runtime->technology;
        $latest = $runtime->status_context['latest'] ?? null;
        $locale = config('psm.alerts.locale');

        $context = [
            'technology' => $technology->getLabel(),
            'version' => $runtime->raw_version,
            'branch' => $runtime->branch,
            'latest' => $latest,
            'reason' => $runtime->status_reason,
            'reason_context' => $runtime->status_context ?? [],
        ];

        if ($rule === FindingRule::RuntimeEol) {
            return new FindingData(
                fingerprint: FindingData::fingerprint($rule, $runtime->deployment_id, "technology:{$technology->value}", $runtime->branch),
                rule: $rule,
                severity: $this->policy->severityFor($rule),
                title: (string) $rule->title($context, $locale),
                groupKey: "{$rule->value}:{$technology->value}:{$runtime->branch}",
                context: [...$context, 'source_url' => $technology->endoflifeUrl()],
                runtimeId: $runtime->id,
                deadline: $this->endOfLifeDate($runtime),
            );
        }

        $patch = is_string($latest) ? IncidentTeamPatch::covering($runtime->deployment->service->project->team_id, $technology, $runtime->branch, $latest) : null;
        $severity = $this->policy->severityFor($rule);
        $related = $this->relatedPatches($runtime);
        $evidence = collect($related)
            ->flatMap(fn (array $relatedPatch): array => $relatedPatch['known_exploited'])
            ->unique('cve')
            ->sortBy('cve')
            ->values()
            ->all();

        return $this->knownExploitation->overlay(new FindingData(
            fingerprint: FindingData::fingerprint($rule, $runtime->deployment_id, "technology:{$technology->value}", $runtime->branch),
            rule: $rule,
            severity: $patch !== null && $patch->severity->rank() > $severity->rank() ? $patch->severity : $severity,
            title: (string) $rule->title($context, $locale),
            groupKey: "{$rule->value}:{$technology->value}:{$latest}",
            context: [
                ...$context,
                'source_url' => $technology->releaseUrl((string) $latest),
                ...($patch === null ? [] : ['covered_by_patch_id' => $patch->id]),
                ...($related === [] ? [] : ['related_patches' => array_map(fn (array $relatedPatch): array => array_diff_key($relatedPatch, ['known_exploited' => true]), $related)]),
            ],
            runtimeId: $runtime->id,
        ), $evidence === [] ? null : $evidence);
    }

    /**
     * The fixes vendors confirmed for the runtime's branch above its version (see Incident::confirmedPatches()), one per
     * current incident the vendor did not say spares the branch, with the pre-notice that confirmed it, its CVE ids and
     * their KEV evidence.
     *
     * @return list<array{incident_id: int, notice_id: int, url: string|null, fixed_version: string, cves: list<string>, known_exploited: list<array<string, mixed>>}>
     */
    private function relatedPatches(Runtime $runtime): array
    {
        if ($runtime->version === null || $runtime->branch === null) {
            return [];
        }

        return collect($this->confirmedPatchesOf($runtime->technology))
            ->flatMap(function (array $item) use ($runtime): array {
                $incident = $item['incident'];
                $patch = $item['patches'][$runtime->branch] ?? null;

                if ($patch === null || ! Version::isNewer($patch['version'], (string) $runtime->version) || $incident->scopeFor($runtime) === IncidentScope::NotAffected) {
                    return [];
                }

                return [[
                    'incident_id' => $incident->id,
                    'notice_id' => $patch['notice']->id,
                    'url' => $patch['notice']->url,
                    'fixed_version' => $patch['version'],
                    'cves' => KnownExploitation::cveIdsOf($patch['notice']),
                    'known_exploited' => $patch['notice']->known_exploited ?? [],
                ]];
            })
            ->sortBy('incident_id')
            ->values()
            ->all();
    }

    /**
     * The fixes vendors confirmed for each current incident of the technology (see Incident::confirmedPatches()).
     *
     * @return list<array{incident: Incident, patches: array<string, array{version: string, notice: Advisory}>}>
     */
    private function confirmedPatchesOf(Technology $technology): array
    {
        if (isset($this->confirmedPatches[$technology->value])) {
            return $this->confirmedPatches[$technology->value];
        }

        $notices = Advisory::where('kind', AdvisoryKind::PreNotice)
            ->where('technology', $technology)
            ->where('is_dismissed', false)
            ->whereNotNull('patch_versions')
            ->whereHas('incident', fn ($query) => $query->whereNull('merged_into_id'))
            ->with('incident')
            ->orderBy('id')
            ->get();
        $securityReleases = Incident::securityReleases($technology, $notices->flatMap(fn (Advisory $notice): array => $notice->patch_versions ?? [])->unique()->values()->all());

        return $this->confirmedPatches[$technology->value] = $notices
            ->groupBy('incident_id')
            ->map(fn ($notices): array => ['incident' => $notices->first()->incident, 'patches' => $notices->first()->incident->confirmedPatches($notices, $securityReleases)])
            ->filter(fn (array $item): bool => $item['patches'] !== [])
            ->values()
            ->all();
    }

    /**
     * The deadline of an end-of-life finding is the day support ended, in the digest timezone, so it is overdue as
     * soon as it opens; when the catalog gives no date, the day it opens.
     */
    private function endOfLifeDate(Runtime $runtime): CarbonInterface
    {
        $date = $runtime->status_context['date'] ?? null;

        return is_string($date) ? Carbon::parse($date, config('psm.digest.timezone')) : now();
    }
}

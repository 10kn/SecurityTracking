<?php

namespace App\Services;

use App\Enums\AdvisoryKind;
use App\Enums\FindingRule;
use App\Enums\RuntimeStatus;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Runtime;

/**
 * Turns evaluated runtimes into findings: eol and outdated_security open one; outdated, ok and unknown do not
 * (outdated and unknown only appear in the daily digest).
 *
 * Handover 5.4: outdated_security is Critical instead of its configured severity while a critical vendor pre-notice
 * of the technology is younger than `psm.notices.escalation_days`.
 */
class RuntimeFindings
{
    /**
     * @var list<FindingRule>
     */
    public const array RULES = [FindingRule::RuntimeEol, FindingRule::RuntimeOutdatedSecurity];

    /**
     * The latest critical pre-notice of each technology, looked up once per evaluation run.
     *
     * @var array<string, Advisory|null>
     */
    private array $criticalPreNotices = [];

    public function __construct(private AlertPolicy $policy) {}

    /**
     * Starts a new evaluation run, so pre-notices stored since the previous one are seen.
     */
    public function forgetPreNotices(): void
    {
        $this->criticalPreNotices = [];
    }

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
                title: __(':technology :branch hết hỗ trợ', ['technology' => $technology->getLabel(), 'branch' => $runtime->branch], $locale),
                groupKey: "{$rule->value}:{$technology->value}:{$runtime->branch}",
                context: [...$context, 'source_url' => $technology->endoflifeUrl()],
                runtimeId: $runtime->id,
            );
        }

        $preNotice = $this->criticalPreNotice($technology);

        if ($preNotice !== null) {
            $context = [...$context, 'pre_notice_id' => $preNotice->id, 'pre_notice_url' => $preNotice->url];
        }

        return new FindingData(
            fingerprint: FindingData::fingerprint($rule, $runtime->deployment_id, "technology:{$technology->value}", $runtime->branch),
            rule: $rule,
            severity: $preNotice !== null ? Severity::Critical : $this->policy->severityFor($rule),
            title: __(':technology :branch thiếu bản vá bảo mật, cần nâng lên :latest', ['technology' => $technology->getLabel(), 'branch' => $runtime->branch, 'latest' => (string) $latest], $locale),
            groupKey: "{$rule->value}:{$technology->value}:{$latest}",
            context: [...$context, 'source_url' => $technology->releaseUrl((string) $latest)],
            runtimeId: $runtime->id,
        );
    }

    private function criticalPreNotice(Technology $technology): ?Advisory
    {
        if (! array_key_exists($technology->value, $this->criticalPreNotices)) {
            $this->criticalPreNotices[$technology->value] = Advisory::where('kind', AdvisoryKind::PreNotice)
                ->where('technology', $technology)
                ->where('severity', Severity::Critical)
                ->where('is_dismissed', false)
                ->where('published_at', '>=', now()->subDays(config('psm.notices.escalation_days')))
                ->latest('published_at')
                ->first();
        }

        return $this->criticalPreNotices[$technology->value];
    }
}

<?php

namespace App\Services;

use App\Enums\FindingRule;
use App\Enums\RuntimeStatus;
use App\Models\Runtime;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Turns evaluated runtimes into findings: eol and outdated_security open one; outdated, ok and unknown do not
 * (outdated and unknown only appear in the daily digest).
 *
 * A vendor pre-notice does not raise their severity: it rarely says which branches are affected, so the team hears
 * about it from its own card (see SendPreNoticeAlerts) instead of every older finding of the technology turning
 * Critical.
 */
class RuntimeFindings
{
    /**
     * @var list<FindingRule>
     */
    public const array RULES = [FindingRule::RuntimeEol, FindingRule::RuntimeOutdatedSecurity];

    public function __construct(private AlertPolicy $policy) {}

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
                deadline: $this->endOfLifeDate($runtime),
            );
        }

        return new FindingData(
            fingerprint: FindingData::fingerprint($rule, $runtime->deployment_id, "technology:{$technology->value}", $runtime->branch),
            rule: $rule,
            severity: $this->policy->severityFor($rule),
            title: __(':technology :branch thiếu bản vá bảo mật, cần nâng lên :latest', ['technology' => $technology->getLabel(), 'branch' => $runtime->branch, 'latest' => (string) $latest], $locale),
            groupKey: "{$rule->value}:{$technology->value}:{$latest}",
            context: [...$context, 'source_url' => $technology->releaseUrl((string) $latest)],
            runtimeId: $runtime->id,
        );
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

<?php

namespace App\Services;

use App\Enums\FindingRule;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\Finding;
use Carbon\CarbonInterface;

/**
 * Which findings and pre-notices are sent right away and which only appear in the daily digest (handover 5.4), read from config/psm.php.
 */
class AlertPolicy
{
    public function severityFor(FindingRule $rule): Severity
    {
        return Severity::tryFrom((string) config("psm.alerts.runtime_severity.{$rule->value}")) ?? Severity::High;
    }

    /**
     * The handling deadline of a finding of `$severity` seen at `$from`, in calendar days. None for unknown severity.
     */
    public function deadlineFor(Severity $severity, CarbonInterface $from): ?CarbonInterface
    {
        $days = config("psm.alerts.deadline_days.{$severity->value}");

        return $days === null ? null : $from->copy()->addDays((int) $days);
    }

    public function isImmediate(Severity $severity): bool
    {
        return in_array($severity->value, config('psm.alerts.immediate_severities'), true);
    }

    /**
     * Pre-notices at the immediate severities are sent right away, and so are those of unknown severity: vendors often
     * give none, and missing one is worse than one card too many. The others only appear in the daily digest.
     */
    public function sendsPreNoticeRightAway(Advisory $preNotice): bool
    {
        return $this->sendsPreNoticeSeverityRightAway($preNotice->severity);
    }

    /**
     * Whether a pre-notice of `$severity` is sent right away (see sendsPreNoticeRightAway()).
     */
    public function sendsPreNoticeSeverityRightAway(Severity $severity): bool
    {
        return $severity === Severity::Unknown || $this->isImmediate($severity);
    }

    /**
     * Whether a team told about an incident at `$toldSeverity` is reminded of it every day until an admin marks it as
     * read: it was told at a severity that is sent right away, unknown included (see sendsPreNoticeRightAway()).
     */
    public function remindsOfIncident(?Severity $toldSeverity): bool
    {
        return $toldSeverity !== null && ($toldSeverity === Severity::Unknown || $this->isImmediate($toldSeverity));
    }

    /**
     * An open finding the team has not been told about yet, or whose severity rose since the last message (a CVE of it
     * newly listed by CISA KEV counts as not told, see SyncFindings). A vulnerable package only installed for
     * development waits for the digest unless `psm.alerts.immediate_dev_dependencies` is on, or CISA KEV lists one of
     * its CVE ids: such a package is exploited in the wild, e.g. on development servers left reachable.
     */
    public function awaitsImmediateAlert(Finding $finding): bool
    {
        return $this->isImmediate($finding->severity)
            && ! $this->isDigestOnlyDevDependency($finding)
            && ($finding->notified_severity === null || $finding->notified_severity->rank() < $finding->severity->rank());
    }

    private function isDigestOnlyDevDependency(Finding $finding): bool
    {
        return ($finding->context['is_dev'] ?? false) === true && ! config('psm.alerts.immediate_dev_dependencies') && blank($finding->context['known_exploited'] ?? null);
    }
}

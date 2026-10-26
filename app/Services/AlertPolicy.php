<?php

namespace App\Services;

use App\Enums\FindingRule;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\Finding;

/**
 * Which findings and pre-notices are sent right away and which only appear in the daily digest (handover 5.4), read from config/psm.php.
 */
class AlertPolicy
{
    public function severityFor(FindingRule $rule): Severity
    {
        return Severity::tryFrom((string) config("psm.alerts.runtime_severity.{$rule->value}")) ?? Severity::High;
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
        return $preNotice->severity === Severity::Unknown || $this->isImmediate($preNotice->severity);
    }

    /**
     * An open finding the team has not been told about yet, or whose severity rose since the last message. A
     * vulnerable package only installed for development waits for the digest unless
     * `psm.alerts.immediate_dev_dependencies` is on.
     */
    public function awaitsImmediateAlert(Finding $finding): bool
    {
        return $this->isImmediate($finding->severity)
            && ! $this->isDigestOnlyDevDependency($finding)
            && ($finding->notified_severity === null || $finding->notified_severity->rank() < $finding->severity->rank());
    }

    private function isDigestOnlyDevDependency(Finding $finding): bool
    {
        return ($finding->context['is_dev'] ?? false) === true && ! config('psm.alerts.immediate_dev_dependencies');
    }
}

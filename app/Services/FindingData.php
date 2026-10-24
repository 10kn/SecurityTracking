<?php

namespace App\Services;

use App\Enums\FindingRule;
use App\Enums\Severity;

/**
 * A finding an evaluation says should be open. `groupKey` puts findings about the same issue into one Google Chat
 * card (handover 5.5); `context` holds what the cards need to describe the issue.
 */
final readonly class FindingData
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $fingerprint,
        public FindingRule $rule,
        public Severity $severity,
        public string $title,
        public string $groupKey,
        public array $context = [],
        public ?int $runtimeId = null,
        public ?int $componentId = null,
        public ?int $advisoryId = null,
    ) {}

    /**
     * Handover 5.4: the rule, the deployment, the technology or package, and the branch or advisory id. Never the
     * running version, so moving from 8.3.10 to 8.3.11 while still missing a fix keeps the same finding.
     */
    public static function fingerprint(FindingRule $rule, int $deploymentId, string $subject, string $key): string
    {
        return hash('sha256', implode('|', [$rule->value, $deploymentId, $subject, $key]));
    }
}

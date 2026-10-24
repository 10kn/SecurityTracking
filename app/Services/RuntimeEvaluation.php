<?php

namespace App\Services;

use App\Enums\RuntimeStatus;

/**
 * The result of evaluating one runtime version. `reason` is a translation key whose placeholders come from `context`.
 */
final readonly class RuntimeEvaluation
{
    /**
     * @param  array<string, string>  $context
     */
    public function __construct(
        public RuntimeStatus $status,
        public string $reason,
        public array $context = [],
        public ?string $version = null,
        public ?string $branch = null,
    ) {}

    public function reasonText(): string
    {
        return __($this->reason, $this->context);
    }
}

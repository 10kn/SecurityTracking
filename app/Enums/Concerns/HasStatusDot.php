<?php

namespace App\Enums\Concerns;

/**
 * For status enums shown as a small dot plus text (prototype v2).
 */
trait HasStatusDot
{
    /**
     * @return array{label: string, tone: 'danger'|'warning'|'success'|'neutral'|'unknown'}
     */
    public function dot(): array
    {
        return ['label' => $this->getLabel(), 'tone' => $this->dotTone()];
    }
}

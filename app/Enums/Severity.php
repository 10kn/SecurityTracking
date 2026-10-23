<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Severity: string implements HasColor, HasLabel
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
            self::Unknown => __('Chưa rõ'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::High => 'warning',
            self::Medium => 'info',
            self::Low, self::Unknown => 'gray',
        };
    }

    /**
     * Higher numbers sort first.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
            self::Unknown => 0,
        };
    }
}

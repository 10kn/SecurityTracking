<?php

namespace App\Enums;

enum Severity: string
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

    /**
     * The qualitative rating of a CVSS score (FIRST): 9.0+ Critical, 7.0+ High, 4.0+ Medium, above 0 Low. A score of
     * 0 rates no severity.
     */
    public static function fromCvss(float $score): self
    {
        return match (true) {
            $score >= 9.0 => self::Critical,
            $score >= 7.0 => self::High,
            $score >= 4.0 => self::Medium,
            $score > 0 => self::Low,
            default => self::Unknown,
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

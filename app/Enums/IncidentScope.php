<?php

namespace App\Enums;

/**
 * Whether an incident affects the branch a service runs, as far as the vendor said so.
 */
enum IncidentScope: string
{
    case Confirmed = 'confirmed';
    case Unknown = 'unknown';
    case NotAffected = 'not_affected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Confirmed => __('đã xác nhận'),
            self::Unknown => __('chưa xác định'),
            self::NotAffected => __('đã xác nhận không ảnh hưởng'),
        };
    }
}

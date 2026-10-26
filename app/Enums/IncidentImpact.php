<?php

namespace App\Enums;

/**
 * Whether an incident affects a team, as an admin assessed it when marking it as read ("Đã đọc"). "Không" stops the
 * team's reminders of the incident while the assessment holds; "Có" and "Chưa rõ" only stop the daily reminder.
 */
enum IncidentImpact: string
{
    case Yes = 'yes';
    case No = 'no';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Yes => __('Có'),
            self::No => __('Không'),
            self::Unknown => __('Chưa rõ'),
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FindingEventType: string implements HasLabel
{
    case Opened = 'opened';
    case Resolved = 'resolved';
    case Escalated = 'escalated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Opened => __('Mở'),
            self::Resolved => __('Tự đóng'),
            self::Escalated => __('Tăng mức'),
        };
    }
}

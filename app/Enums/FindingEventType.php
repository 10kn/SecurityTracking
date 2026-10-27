<?php

namespace App\Enums;

enum FindingEventType: string
{
    case Opened = 'opened';
    case Resolved = 'resolved';
    case Escalated = 'escalated';
    case Acknowledged = 'acknowledged';
    case KnownExploited = 'known_exploited';

    public function getLabel(): string
    {
        return match ($this) {
            self::Opened => __('Mở'),
            self::Resolved => __('Tự đóng'),
            self::Escalated => __('Tăng mức'),
            self::Acknowledged => __('Đã đọc'),
            self::KnownExploited => __('CISA KEV ghi nhận bị khai thác'),
        };
    }
}

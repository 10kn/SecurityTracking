<?php

namespace App\Enums;

/**
 * What happened to an incident at one team (see IncidentTeamEvent).
 */
enum IncidentTeamEventType: string
{
    case Acknowledged = 'acknowledged';
    case ImpactReset = 'impact_reset';
    case UpdateSent = 'update_sent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Acknowledged => __('Đã đọc'),
            self::ImpactReset => __('Đánh giá hết hiệu lực'),
            self::UpdateSent => __('Gửi cập nhật khẩn'),
        };
    }
}

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

    /**
     * What the scope means for the admin assessing the team, shown beside its label on the incident page.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Confirmed => __('Tin trên trang chính thức ghi rõ nhánh này bị ảnh hưởng.'),
            self::Unknown => __('Tin trên trang chính thức không nói nhánh này có bị ảnh hưởng hay không, hoặc nhánh đã hết hỗ trợ (EOL). Không có nghĩa là an toàn: đọc tin rồi đánh giá cho team.'),
            self::NotAffected => __('Tin trên trang chính thức ghi rõ nhánh này không bị ảnh hưởng.'),
        };
    }
}

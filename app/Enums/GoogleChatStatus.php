<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum GoogleChatStatus: string implements HasLabel
{
    case Active = 'active';
    case Failing = 'failing';
    case NotConfigured = 'not_configured';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Hoạt động'),
            self::Failing => __('Lỗi gửi'),
            self::NotConfigured => __('Chưa cấu hình'),
        };
    }

    /**
     * @return 'danger'|'warning'|'success'|'neutral'|'unknown'
     */
    public function dotTone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Failing => 'danger',
            self::NotConfigured => 'neutral',
        };
    }
}

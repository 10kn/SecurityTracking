<?php

namespace App\Enums;

use App\Enums\Concerns\HasStatusDot;

enum RuntimeStatus: string
{
    use HasStatusDot;

    case Vulnerable = 'vulnerable';
    case Eol = 'eol';
    case OutdatedSecurity = 'outdated_security';
    case Outdated = 'outdated';
    case Ok = 'ok';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vulnerable => __('Có lỗ hổng'),
            self::Eol => __('Hết hỗ trợ'),
            self::OutdatedSecurity => __('Thiếu bản vá bảo mật'),
            self::Outdated => __('Có bản mới hơn'),
            self::Ok => __('Đạt'),
            self::Unknown => __('Thiếu dữ liệu'),
        };
    }

    /**
     * Tone of the status dot. Unknown gets a dashed outline so it never looks like Ok.
     *
     * @return 'danger'|'warning'|'success'|'neutral'|'unknown'
     */
    public function dotTone(): string
    {
        return match ($this) {
            self::Vulnerable, self::Eol => 'danger',
            self::OutdatedSecurity => 'warning',
            self::Outdated => 'neutral',
            self::Ok => 'success',
            self::Unknown => 'unknown',
        };
    }
}

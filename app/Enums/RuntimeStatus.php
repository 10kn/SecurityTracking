<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum RuntimeStatus: string implements HasColor, HasIcon, HasLabel
{
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

    public function getColor(): string
    {
        return match ($this) {
            self::Vulnerable, self::Eol => 'danger',
            self::OutdatedSecurity, self::Unknown => 'warning',
            self::Outdated => 'gray',
            self::Ok => 'success',
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

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Vulnerable => Heroicon::OutlinedShieldExclamation,
            self::Eol => Heroicon::OutlinedNoSymbol,
            self::OutdatedSecurity => Heroicon::OutlinedExclamationTriangle,
            self::Outdated => Heroicon::OutlinedArrowUpCircle,
            self::Ok => Heroicon::OutlinedCheckCircle,
            self::Unknown => Heroicon::OutlinedQuestionMarkCircle,
        };
    }
}

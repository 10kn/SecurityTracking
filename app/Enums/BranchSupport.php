<?php

namespace App\Enums;

use App\Enums\Concerns\HasStatusDot;

/**
 * Where a release branch is in its support life, from its endoflife.date dates.
 */
enum BranchSupport: string
{
    use HasStatusDot;

    case Active = 'active';
    case SecurityOnly = 'security_only';
    case Eol = 'eol';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Đang hỗ trợ'),
            self::SecurityOnly => __('Chỉ vá bảo mật'),
            self::Eol => __('Hết hỗ trợ'),
        };
    }

    /**
     * @return 'danger'|'warning'|'success'|'neutral'|'unknown'
     */
    public function dotTone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::SecurityOnly => 'warning',
            self::Eol => 'danger',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * MVP only has the two automatic states; the manual lifecycle comes after the MVP.
 */
enum FindingState: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Đang mở'),
            self::Resolved => __('Đã đóng'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Resolved => 'success',
        };
    }
}

<?php

namespace App\Enums;

/**
 * MVP only has the two automatic states; the manual lifecycle comes after the MVP.
 */
enum FindingState: string
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
}

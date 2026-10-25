<?php

namespace App\Enums;

enum LockfileSource: string
{
    case Upload = 'upload';
    case GitHub = 'github';

    public function getLabel(): string
    {
        return match ($this) {
            self::Upload => __('Tải lên'),
            self::GitHub => 'GitHub',
        };
    }
}

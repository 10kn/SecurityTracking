<?php

namespace App\Enums;

/**
 * What happened to the monitoring of a project (see ProjectEvent).
 */
enum ProjectEventType: string
{
    case Paused = 'paused';
    case Resumed = 'resumed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Paused => __('Ngừng theo dõi'),
            self::Resumed => __('Mở lại'),
        };
    }
}

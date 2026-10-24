<?php

namespace App\Enums;

enum ServiceKind: string
{
    case Server = 'server';
    case Ecs = 'ecs';

    public function getLabel(): string
    {
        return match ($this) {
            self::Server => __('Server'),
            self::Ecs => 'ECS',
        };
    }
}

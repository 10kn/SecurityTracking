<?php

namespace App\Enums;

enum AssetKind: string
{
    case Host = 'host';
    case EcsTask = 'ecs_task';

    public function getLabel(): string
    {
        return match ($this) {
            self::Host => 'Host',
            self::EcsTask => 'ECS task',
        };
    }
}

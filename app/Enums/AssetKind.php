<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AssetKind: string implements HasLabel
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

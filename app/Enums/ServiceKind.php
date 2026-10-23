<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ServiceKind: string implements HasLabel
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

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Environment: string implements HasColor, HasLabel
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Production => 'danger',
            self::Staging => 'warning',
            self::Development => 'gray',
        };
    }
}

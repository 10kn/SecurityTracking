<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Exposure: string implements HasLabel
{
    case Public = 'public';
    case Internal = 'internal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Public => __('Public'),
            self::Internal => __('Nội bộ'),
        };
    }
}

<?php

namespace App\Enums;

enum Exposure: string
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

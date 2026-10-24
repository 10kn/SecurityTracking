<?php

namespace App\Enums;

enum Environment: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}

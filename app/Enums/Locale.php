<?php

namespace App\Enums;

enum Locale: string
{
    case Vietnamese = 'vi';
    case Japanese = 'ja';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vietnamese => __('Tiếng Việt'),
            self::Japanese => '日本語',
        };
    }

    /**
     * Label on the topbar language switch.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Vietnamese => 'VI',
            self::Japanese => '日本語',
        };
    }
}

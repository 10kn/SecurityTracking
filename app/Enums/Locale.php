<?php

namespace App\Enums;

use Illuminate\Support\Facades\App;

enum Locale: string
{
    case Vietnamese = 'vi';
    case Japanese = 'ja';

    /**
     * The locale strings are being translated into; Vietnamese for one this application has no case for.
     */
    public static function current(): self
    {
        return self::tryFrom(App::getLocale()) ?? self::Vietnamese;
    }

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

    /**
     * Times are shown in the timezone of the reader's language: Vietnam for Vietnamese, Japan for Japanese.
     */
    public function timezone(): string
    {
        return match ($this) {
            self::Vietnamese => 'Asia/Ho_Chi_Minh',
            self::Japanese => 'Asia/Tokyo',
        };
    }

    /**
     * The name shown after a time, e.g. "28/10/2026 14:00 GMT+7".
     */
    public function timezoneLabel(): string
    {
        return match ($this) {
            self::Vietnamese => 'GMT+7',
            self::Japanese => 'JST',
        };
    }
}

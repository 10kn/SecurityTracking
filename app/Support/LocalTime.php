<?php

namespace App\Support;

use App\Enums\Locale;
use Carbon\CarbonInterface;

/**
 * Times as the reader sees them: in the timezone of the current locale (Locale::timezone()), with its name after the
 * time of day. Times are stored in UTC. Days that are deadlines stay in the digest timezone, where they are decided.
 */
final class LocalTime
{
    /**
     * The same moment in the timezone of the current locale.
     */
    public static function of(CarbonInterface $time): CarbonInterface
    {
        return $time->copy()->setTimezone(Locale::current()->timezone());
    }

    /**
     * E.g. "28/10/2026 14:00 GMT+7" in Vietnamese, "2026/10/28 16:00 JST" in Japanese. A format other than "d/m/Y H:i"
     * is translated by the caller.
     */
    public static function format(?CarbonInterface $time, ?string $format = null): ?string
    {
        return $time === null ? null : self::withTimezone(self::of($time)->translatedFormat($format ?? __('d/m/Y H:i')));
    }

    /**
     * E.g. "14:00 GMT+7".
     */
    public static function timeOfDay(CarbonInterface $time): string
    {
        return self::withTimezone(self::of($time)->format('H:i'));
    }

    /**
     * The day of the time in the timezone of the current locale, e.g. "28/10/2026".
     */
    public static function date(?CarbonInterface $time): ?string
    {
        return $time === null ? null : self::of($time)->translatedFormat(__('d/m/Y'));
    }

    private static function withTimezone(string $time): string
    {
        return $time.' '.Locale::current()->timezoneLabel();
    }
}

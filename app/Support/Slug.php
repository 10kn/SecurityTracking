<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Str;

/**
 * Slugs of teams, projects and services.
 */
final class Slug
{
    /**
     * Lowercase letters, digits and single hyphens, e.g. "internal-tools".
     */
    public const string PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * Room left for a "-999" suffix under the 255 characters of the column.
     */
    private const int MAX_BASE_LENGTH = 240;

    /**
     * The slug of a name, as the forms suggest it (resources/js/lib/slug.ts), e.g. "Thanh toán Đà Nẵng" →
     * "thanh-toan-da-nang". A name with nothing to keep, e.g. only Japanese characters, gives the fallback.
     */
    public static function from(string $name, string $fallback): string
    {
        $slug = trim(Str::limit(Str::slug($name), self::MAX_BASE_LENGTH, ''), '-');

        return preg_match(self::PATTERN, $slug) === 1 ? $slug : $fallback;
    }

    /**
     * The slug itself when it is free, otherwise the first free one of "slug-2", "slug-3"…
     *
     * @param  Closure(string): bool  $isTaken
     */
    public static function unique(string $slug, Closure $isTaken): string
    {
        $candidate = $slug;

        for ($suffix = 2; $isTaken($candidate); $suffix++) {
            $candidate = "{$slug}-{$suffix}";
        }

        return $candidate;
    }
}

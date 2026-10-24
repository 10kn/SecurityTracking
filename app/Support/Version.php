<?php

namespace App\Support;

/**
 * Helpers for the dotted numeric versions that runtimes and products publish, e.g. "8.3.12" or "20.11.0".
 */
final class Version
{
    /**
     * The upstream version inside what an admin or a server reports.
     *
     * "v20.11.0" → "20.11.0", "8.3.12-1+deb.sury.org+1" → "8.3.12", "2.7.8p225" → "2.7.8". Returns null when the text
     * does not start with a number.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null || preg_match('/^\s*v?(\d+(?:\.\d+)*)/i', $raw, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The longest branch the version belongs to, e.g. "8.3" for "8.3.12" and "20" for "20.11.0".
     *
     * @param  iterable<string>  $branches
     */
    public static function branchOf(string $version, iterable $branches): ?string
    {
        $match = null;

        foreach ($branches as $branch) {
            if (($version === $branch || str_starts_with($version, $branch.'.'))
                && ($match === null || strlen($branch) > strlen($match))) {
                $match = $branch;
            }
        }

        return $match;
    }

    /**
     * Whether the version names a release inside the branch rather than the branch itself, e.g. "8.3.12" but not "8.3".
     */
    public static function isReleaseOf(string $version, string $branch): bool
    {
        return str_starts_with($version, $branch.'.');
    }

    public static function isNewer(string $version, string $than): bool
    {
        return version_compare($version, $than, '>');
    }
}

<?php

namespace App\Support;

use App\Enums\Ecosystem;

/**
 * Where a lockfile sits, relative to the directory of its service: "composer.lock" directly in it,
 * "frontend/package-lock.json" in a subdirectory. Uploads and lockfiles read from a repository follow the same rules,
 * so a path never climbs out of the service directory and is safe to show in Google Chat cards.
 */
final class LockfilePath
{
    /**
     * One directory or file name: letters, digits and . _ @ + -, e.g. "@acme" or "web-1.2".
     */
    public const string SEGMENT_PATTERN = '/\A[A-Za-z0-9._@+-]+\z/';

    /**
     * The length of `lockfiles.path` and `components.lockfile_path`.
     */
    public const int MAX_LENGTH = 255;

    /**
     * E.g. "frontend" and "package-lock.json" → "frontend/package-lock.json". Slashes around the directory are dropped;
     * a blank directory means the service directory itself.
     */
    public static function join(?string $directory, string $name): string
    {
        $directory = trim(trim((string) $directory), '/');

        return $directory === '' ? $name : "{$directory}/{$name}";
    }

    /**
     * Whether $path follows the rules and names a lockfile the hub can read.
     */
    public static function isValid(string $path): bool
    {
        if ($path === '' || mb_strlen($path) > self::MAX_LENGTH) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..' || preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                return false;
            }
        }

        return self::ecosystem($path) !== null;
    }

    /**
     * The ecosystem of the lockfile, by its file name.
     */
    public static function ecosystem(string $path): ?Ecosystem
    {
        return Ecosystem::forLockfile(basename($path));
    }

    /**
     * Whether $path is $excluded or lies inside it, comparing whole segments: "legacy" excludes
     * "legacy/package-lock.json" but not "legacy-ui/package-lock.json".
     */
    public static function isWithin(string $path, string $excluded): bool
    {
        $excluded = trim($excluded, '/');

        return $excluded !== '' && ($path === $excluded || str_starts_with($path, "{$excluded}/"));
    }
}

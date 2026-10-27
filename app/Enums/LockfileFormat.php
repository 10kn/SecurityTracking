<?php

namespace App\Enums;

use App\Services\Lockfiles\ComposerLockParser;
use App\Services\Lockfiles\GemfileLockParser;
use App\Services\Lockfiles\LockfileParser;
use App\Services\Lockfiles\NpmLockParser;
use App\Services\Lockfiles\PnpmLockParser;
use App\Services\Lockfiles\YarnLockParser;

/**
 * The lockfiles the hub reads, by file name. A lockfile's path may include its directory, e.g. `web/yarn.lock`.
 *
 * npm, pnpm and Yarn all install from the npm registry. A directory with several of their lockfiles is installed with
 * one package manager only, and the others are usually left behind, so only one is read: pnpm-lock.yaml, else
 * yarn.lock, else package-lock.json, which a stray `npm install` creates next to the others.
 */
enum LockfileFormat: string
{
    case ComposerLock = 'composer.lock';
    case GemfileLock = 'Gemfile.lock';
    case PnpmLock = 'pnpm-lock.yaml';
    case YarnLock = 'yarn.lock';
    case PackageLock = 'package-lock.json';

    public static function forPath(string $path): ?self
    {
        return self::tryFrom(basename($path));
    }

    public function ecosystem(): Ecosystem
    {
        return match ($this) {
            self::ComposerLock => Ecosystem::Packagist,
            self::GemfileLock => Ecosystem::Rubygems,
            self::PnpmLock, self::YarnLock, self::PackageLock => Ecosystem::Npm,
        };
    }

    public function parser(): LockfileParser
    {
        return match ($this) {
            self::ComposerLock => app(ComposerLockParser::class),
            self::GemfileLock => app(GemfileLockParser::class),
            self::PnpmLock => app(PnpmLockParser::class),
            self::YarnLock => app(YarnLockParser::class),
            self::PackageLock => app(NpmLockParser::class),
        };
    }

    /**
     * The lockfiles not read because another lockfile of the same ecosystem is in the same directory, each mapped to
     * the path of the one read instead. The cases are declared in order of precedence.
     *
     * @param  iterable<string>  $paths
     * @return array<string, string>
     */
    public static function supersededPaths(iterable $paths): array
    {
        $inUse = [];
        $superseded = [];

        $formats = collect($paths)
            ->mapWithKeys(fn (string $path): array => [$path => self::forPath($path)])
            ->filter()
            ->sortBy(fn (self $format): int => (int) array_search($format, self::cases(), true));

        foreach ($formats as $path => $format) {
            $group = dirname($path)."\0".$format->ecosystem()->value;

            if (isset($inUse[$group])) {
                $superseded[(string) $path] = $inUse[$group];
            } else {
                $inUse[$group] = (string) $path;
            }
        }

        return $superseded;
    }
}

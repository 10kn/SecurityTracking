<?php

namespace App\Enums;

use App\Services\Lockfiles\ComposerLockParser;
use App\Services\Lockfiles\GemfileLockParser;
use App\Services\Lockfiles\GitHubWorkflowParser;
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
 *
 * GitHubWorkflow is not a file name: it is every workflow of the repository (`.github/workflows/*.yml`) and every
 * local action (`.github/actions/…/action.yml`), stored by their path from the root of the repository, wherever the
 * service directory is, since GitHub only runs workflows from there. They are only read from the repository.
 */
enum LockfileFormat: string
{
    case ComposerLock = 'composer.lock';
    case GemfileLock = 'Gemfile.lock';
    case PnpmLock = 'pnpm-lock.yaml';
    case YarnLock = 'yarn.lock';
    case PackageLock = 'package-lock.json';
    case GitHubWorkflow = 'github-workflow';

    /**
     * `.github/workflows/{name}.yml` or `.yaml` (GitHub does not read subdirectories), and `action.yml` or `action.yaml`
     * anywhere under `.github/actions`.
     */
    private const string WORKFLOW_PATTERN = '#\A\.github/(?:workflows/[^/]+\.ya?ml|actions/(?:[^/]+/)*action\.ya?ml)\z#';

    public static function forPath(string $path): ?self
    {
        if (preg_match(self::WORKFLOW_PATTERN, $path) === 1) {
            return self::GitHubWorkflow;
        }

        $format = self::tryFrom(basename($path));

        return $format?->isFileName() ? $format : null;
    }

    /**
     * Whether the value is the name of the file, as for every lockfile.
     */
    public function isFileName(): bool
    {
        return $this !== self::GitHubWorkflow;
    }

    public function ecosystem(): Ecosystem
    {
        return match ($this) {
            self::ComposerLock => Ecosystem::Packagist,
            self::GemfileLock => Ecosystem::Rubygems,
            self::PnpmLock, self::YarnLock, self::PackageLock => Ecosystem::Npm,
            self::GitHubWorkflow => Ecosystem::GitHubActions,
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
            self::GitHubWorkflow => app(GitHubWorkflowParser::class),
        };
    }

    /**
     * The lockfiles not read because another lockfile of the same ecosystem is in the same directory, each mapped to
     * the path of the one read instead. The cases are declared in order of precedence. Every workflow is read.
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
            ->filter(fn (?self $format): bool => $format !== null && $format !== self::GitHubWorkflow)
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

<?php

namespace App\Enums;

enum Ecosystem: string
{
    case Packagist = 'packagist';
    case Rubygems = 'rubygems';
    case Npm = 'npm';
    case GitHubActions = 'github_actions';

    public function getLabel(): string
    {
        return match ($this) {
            self::Packagist => 'Composer',
            self::Rubygems => 'RubyGems',
            self::Npm => 'npm',
            self::GitHubActions => 'GitHub Actions',
        };
    }

    /**
     * The lockfile names admins may upload, mapped to their ecosystem. GitHub Actions workflows are only read from the
     * repository, so they are not among them.
     *
     * @return array<string, self>
     */
    public static function lockfileNames(): array
    {
        return collect(LockfileFormat::cases())
            ->filter(fn (LockfileFormat $format): bool => $format->isFileName())
            ->mapWithKeys(fn (LockfileFormat $format): array => [$format->value => $format->ecosystem()])
            ->all();
    }

    public static function forLockfile(string $filename): ?self
    {
        return self::lockfileNames()[$filename] ?? null;
    }

    /**
     * The ecosystem name OSV uses in queries and results.
     */
    public function osvName(): string
    {
        return match ($this) {
            self::Packagist => 'Packagist',
            self::Rubygems => 'RubyGems',
            self::Npm => 'npm',
            self::GitHubActions => 'GitHub Actions',
        };
    }

    public static function fromOsvName(string $name): ?self
    {
        foreach (self::cases() as $ecosystem) {
            if ($ecosystem->osvName() === $name) {
                return $ecosystem;
            }
        }

        return null;
    }

    /**
     * The package-url type (https://github.com/package-url/purl-spec).
     */
    public function purlType(): string
    {
        return match ($this) {
            self::Packagist => 'composer',
            self::Rubygems => 'gem',
            self::Npm => 'npm',
            self::GitHubActions => 'githubactions',
        };
    }

    /**
     * What one of its packages is called on admin pages, e.g. ":count package" or ":count action".
     */
    public function countLabel(int $count): string
    {
        return match ($this) {
            self::GitHubActions => __(':count action', ['count' => $count]),
            default => __(':count package', ['count' => $count]),
        };
    }
}

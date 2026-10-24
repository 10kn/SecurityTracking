<?php

namespace App\Enums;

use App\Services\Lockfiles\ComposerLockParser;
use App\Services\Lockfiles\GemfileLockParser;
use App\Services\Lockfiles\LockfileParser;
use App\Services\Lockfiles\NpmLockParser;

enum Ecosystem: string
{
    case Packagist = 'packagist';
    case Rubygems = 'rubygems';
    case Npm = 'npm';

    public function getLabel(): string
    {
        return match ($this) {
            self::Packagist => 'Composer',
            self::Rubygems => 'RubyGems',
            self::Npm => 'npm',
        };
    }

    /**
     * The lockfile names admins may upload, mapped to their ecosystem.
     *
     * @return array<string, self>
     */
    public static function lockfileNames(): array
    {
        return [
            'composer.lock' => self::Packagist,
            'Gemfile.lock' => self::Rubygems,
            'package-lock.json' => self::Npm,
        ];
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
        };
    }

    public function lockfileParser(): LockfileParser
    {
        return match ($this) {
            self::Packagist => app(ComposerLockParser::class),
            self::Rubygems => app(GemfileLockParser::class),
            self::Npm => app(NpmLockParser::class),
        };
    }
}

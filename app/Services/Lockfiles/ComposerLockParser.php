<?php

namespace App\Services\Lockfiles;

use App\Enums\Ecosystem;
use JsonException;

/**
 * composer.lock: `packages` are installed in production, `packages-dev` only with `composer install --dev`.
 */
class ComposerLockParser implements LockfileParser
{
    public function parse(string $content): array
    {
        try {
            $lock = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnreadableLockfileException("Not valid JSON: {$exception->getMessage()}.", previous: $exception);
        }

        if (! is_array($lock) || ! is_array($lock['packages'] ?? null)) {
            throw new UnreadableLockfileException('No "packages" list.');
        }

        $components = [];

        foreach (['packages' => false, 'packages-dev' => true] as $key => $isDev) {
            $packages = $lock[$key] ?? [];

            if (! is_array($packages)) {
                throw new UnreadableLockfileException("\"{$key}\" is not a list.");
            }

            foreach (array_values($packages) as $index => $package) {
                if (! is_array($package) || ! is_string($package['name'] ?? null) || ! is_string($package['version'] ?? null)) {
                    throw new UnreadableLockfileException("Entry {$index} of \"{$key}\" has no name or version.");
                }

                $components[] = new ComponentData(Ecosystem::Packagist, $package['name'], $package['version'], $isDev);
            }
        }

        return ComponentData::unique($components);
    }
}

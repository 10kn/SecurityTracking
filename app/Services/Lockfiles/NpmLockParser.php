<?php

namespace App\Services\Lockfiles;

use App\Enums\Ecosystem;
use JsonException;

/**
 * package-lock.json. Version 2 and 3 list every installed path under `packages`; version 1 nests `dependencies`.
 * The same package can be installed at several versions, each one a component. `dev` and `devOptional` packages are
 * only installed for development.
 *
 * Left out: the root project, workspace folders and their links, and packages installed from git or a local path,
 * which OSV cannot know.
 */
class NpmLockParser implements LockfileParser
{
    public function parse(string $content): array
    {
        try {
            $lock = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnreadableLockfileException("Not valid JSON: {$exception->getMessage()}.", previous: $exception);
        }

        if (! is_array($lock) || (! isset($lock['lockfileVersion']) && ! isset($lock['packages']) && ! isset($lock['dependencies']))) {
            throw new UnreadableLockfileException('Not a package-lock.json: no "lockfileVersion".');
        }

        if ((int) ($lock['lockfileVersion'] ?? 1) >= 2) {
            if (! is_array($lock['packages'] ?? null)) {
                throw new UnreadableLockfileException('No "packages" object.');
            }

            return ComponentData::unique($this->fromPackages($lock['packages']));
        }

        $dependencies = $lock['dependencies'] ?? [];

        if (! is_array($dependencies)) {
            throw new UnreadableLockfileException('"dependencies" is not an object.');
        }

        return ComponentData::unique($this->fromDependencies($dependencies));
    }

    /**
     * @param  array<mixed>  $packages
     * @return list<ComponentData>
     */
    private function fromPackages(array $packages): array
    {
        $components = [];

        foreach ($packages as $path => $package) {
            $path = (string) $path;

            if (! is_array($package)) {
                throw new UnreadableLockfileException("Entry \"{$path}\" is not an object.");
            }

            $position = strrpos($path, 'node_modules/');

            if ($position === false || ($package['link'] ?? false) === true || ! is_string($package['version'] ?? null) || ! $this->isFromRegistry($package)) {
                continue;
            }

            $name = is_string($package['name'] ?? null) ? $package['name'] : substr($path, $position + strlen('node_modules/'));
            $isDev = ($package['dev'] ?? false) === true || ($package['devOptional'] ?? false) === true;

            $components[] = new ComponentData(Ecosystem::Npm, $name, $package['version'], $isDev);
        }

        return $components;
    }

    /**
     * @param  array<mixed>  $dependencies
     * @return list<ComponentData>
     */
    private function fromDependencies(array $dependencies): array
    {
        $components = [];

        foreach ($dependencies as $name => $dependency) {
            if (! is_array($dependency) || ! is_string($dependency['version'] ?? null)) {
                throw new UnreadableLockfileException("Dependency \"{$name}\" has no version.");
            }

            $version = $dependency['version'];

            if (preg_match('/^npm:(.+)@([^@]+)$/', $version, $alias) === 1) {
                [, $name, $version] = $alias;
            }

            if (preg_match('/^\d/', $version) === 1 && $this->isFromRegistry($dependency)) {
                $components[] = new ComponentData(Ecosystem::Npm, (string) $name, $version, ($dependency['dev'] ?? false) === true);
            }

            if (is_array($dependency['dependencies'] ?? null)) {
                array_push($components, ...$this->fromDependencies($dependency['dependencies']));
            }
        }

        return $components;
    }

    /**
     * Packages fetched from git or a local folder carry their own version numbers, which say nothing about the
     * registry package of the same name.
     *
     * @param  array<mixed>  $package
     */
    private function isFromRegistry(array $package): bool
    {
        $resolved = $package['resolved'] ?? null;

        return ! is_string($resolved) || preg_match('#^https?://#i', $resolved) === 1;
    }
}

<?php

namespace App\Services\Lockfiles;

use App\Enums\Ecosystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * pnpm-lock.yaml of pnpm 7 (lockfile 5.x), pnpm 8 (6.x) and pnpm 9 and later (9.x). `packages` lists every installed
 * package once per version, keyed `/name/1.2.3` (5.x), `/name@1.2.3` (6.x) or `name@1.2.3` (9.x). The dependency graph
 * is in `packages` itself (5.x, 6.x) or under `snapshots` (9.x), keyed with the peer dependencies or patch of the copy
 * appended (`_react@18.2.0` in 5.x, `(react@18.2.0)` later).
 *
 * Lockfiles 5.x and 6.x mark packages only installed for development with `dev: true`. 9.x no longer does, so its graph
 * is walked from the `importers`, the project and each workspace folder: a package is a dev dependency only when it is
 * reached from `devDependencies` and never from `dependencies` or `optionalDependencies`. A package the walk does not
 * reach counts as production.
 *
 * Every dependency in the graph must be in the lockfile, so a lockfile cut off part way is not read as a project with
 * fewer packages. pnpm 10 and later may write the package manager's own lockfile as a first YAML document; the
 * project's lockfile is the last one. Left out: workspace folders and their links, and packages installed from git, a
 * tarball URL or a local path, which OSV cannot know.
 */
class PnpmLockParser implements LockfileParser
{
    private const array SUPPORTED_MAJOR_VERSIONS = [5, 6, 9];

    private const array PRODUCTION_GROUPS = ['dependencies', 'optionalDependencies'];

    public function parse(string $content): array
    {
        $lock = $this->projectDocument($content);
        $major = (int) $lock['lockfileVersion'];

        if (! in_array($major, self::SUPPORTED_MAJOR_VERSIONS, true)) {
            throw new UnreadableLockfileException("pnpm lockfile version {$lock['lockfileVersion']} is not supported (5.x, 6.x and 9.x are).");
        }

        $packages = $this->map($lock, 'packages');
        $graph = $major >= 9 ? $this->map($lock, 'snapshots') : $packages;

        $this->assertComplete($this->importers($lock), $packages, $graph, $major);

        [$production, $development] = $major >= 9 ? $this->walkFromImporters($this->importers($lock), $graph) : [[], []];
        $components = [];

        foreach ($packages as $key => $package) {
            $nameAndVersion = $this->nameAndVersion((string) $key, $major);

            if ($nameAndVersion === null) {
                continue;
            }

            [$name, $version] = $nameAndVersion;
            $isDev = $major >= 9
                ? isset($development["{$name}@{$version}"]) && ! isset($production["{$name}@{$version}"])
                : ($package['dev'] ?? false) === true;

            $components[] = new ComponentData(Ecosystem::Npm, $name, $version, $isDev);
        }

        return ComponentData::unique($components);
    }

    /**
     * @return array<mixed>
     */
    private function projectDocument(string $content): array
    {
        $documents = array_filter(preg_split('/^---[ \t]*\r?$/m', $content) ?: [], fn (string $document): bool => trim($document) !== '');
        $project = null;

        foreach ($documents as $document) {
            try {
                $project = Yaml::parse($document);
            } catch (ParseException $exception) {
                throw new UnreadableLockfileException("Not valid YAML: {$exception->getMessage()}", previous: $exception);
            }
        }

        if (! is_array($project) || ! is_scalar($project['lockfileVersion'] ?? null)) {
            throw new UnreadableLockfileException('Not a pnpm-lock.yaml: no "lockfileVersion".');
        }

        return $project;
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private function map(array $node, string $key): array
    {
        $map = $node[$key] ?? [];

        if (! is_array($map)) {
            throw new UnreadableLockfileException("\"{$key}\" is not a map.");
        }

        return $map;
    }

    /**
     * The project and its workspace folders, by path. Lockfiles 5.x and 6.x outside a workspace list the project's
     * dependencies at the top instead of under `importers`.
     *
     * @param  array<mixed>  $lock
     * @return array<array<mixed>>
     */
    private function importers(array $lock): array
    {
        $importers = isset($lock['importers']) ? $this->map($lock, 'importers') : ['.' => $lock];

        foreach ($importers as $path => $importer) {
            if (! is_array($importer)) {
                throw new UnreadableLockfileException("Importer \"{$path}\" is not a map.");
            }
        }

        return $importers;
    }

    /**
     * Every dependency of an importer or a graph node is a node, and in 9.x every snapshot has its package.
     *
     * @param  array<array<mixed>>  $importers
     * @param  array<mixed>  $packages
     * @param  array<mixed>  $graph
     */
    private function assertComplete(array $importers, array $packages, array $graph, int $major): void
    {
        $dependents = [];

        foreach ($importers as $path => $importer) {
            $dependents["importer \"{$path}\""] = $this->dependencyKeys($importer, [...self::PRODUCTION_GROUPS, 'devDependencies'], $graph, $major);
        }

        foreach ($graph as $key => $node) {
            if (! is_array($node)) {
                throw new UnreadableLockfileException("\"{$key}\" is not a map.");
            }

            if ($major >= 9 && ! array_key_exists($this->withoutPeersOrPatch((string) $key), $packages)) {
                throw new UnreadableLockfileException("Snapshot \"{$key}\" has no package.");
            }

            $dependents["\"{$key}\""] = $this->dependencyKeys($node, self::PRODUCTION_GROUPS, $graph, $major);
        }

        foreach ($dependents as $dependent => $keys) {
            foreach ($keys as $key) {
                if (! array_key_exists($key, $graph)) {
                    throw new UnreadableLockfileException("Dependency \"{$key}\" of {$dependent} is not in the lockfile.");
                }
            }
        }
    }

    /**
     * The `name@version` of the packages reached from production dependencies and from development dependencies.
     *
     * @param  array<array<mixed>>  $importers
     * @param  array<mixed>  $snapshots
     * @return array{array<string, true>, array<string, true>}
     */
    private function walkFromImporters(array $importers, array $snapshots): array
    {
        $production = [];
        $development = [];

        foreach ($importers as $importer) {
            $production += $this->reach($snapshots, $this->dependencyKeys($importer, self::PRODUCTION_GROUPS, $snapshots, 9));
            $development += $this->reach($snapshots, $this->dependencyKeys($importer, ['devDependencies'], $snapshots, 9));
        }

        return [$production, $development];
    }

    /**
     * Follows the snapshots from the given keys. Every snapshot is followed, also the ones that are not registry
     * packages, such as a workspace folder injected with `file:`.
     *
     * @param  array<mixed>  $snapshots
     * @param  list<string>  $keys
     * @return array<string, true>
     */
    private function reach(array $snapshots, array $keys): array
    {
        $visited = [];
        $reached = [];

        while ($keys !== []) {
            $key = array_pop($keys);

            if (isset($visited[$key]) || ! is_array($snapshots[$key] ?? null)) {
                continue;
            }

            $visited[$key] = true;

            if (($nameAndVersion = $this->nameAndVersion($key, 9)) !== null) {
                $reached[implode('@', $nameAndVersion)] = true;
            }

            array_push($keys, ...$this->dependencyKeys($snapshots[$key], self::PRODUCTION_GROUPS, $snapshots, 9));
        }

        return $reached;
    }

    /**
     * The graph keys an importer or a graph node depends on. A dependency's version is a registry version, with peers
     * or a patch (`18.2.0(react@18.2.0)`, `18.2.0_react@18.2.0` in 5.x), the key of an alias (`lodash@4.17.20`, also
     * `7zip-bin@5.2.0`; `/lodash@4.17.20` in 6.x, `/lodash/4.17.20` in 5.x), another source (`file:…`, a git host) or a
     * `link:` to a workspace folder, which has no node. Importers of 6.x and later give `{specifier, version}`, the rest
     * the version alone.
     *
     * @param  array<mixed>  $node
     * @param  list<string>  $groups
     * @param  array<mixed>  $graph
     * @return list<string>
     */
    private function dependencyKeys(array $node, array $groups, array $graph, int $major): array
    {
        $keys = [];

        foreach ($groups as $group) {
            foreach ($this->map($node, $group) as $name => $dependency) {
                $version = is_array($dependency) ? ($dependency['version'] ?? null) : $dependency;

                if (! is_scalar($version)) {
                    throw new UnreadableLockfileException("Dependency \"{$name}\" has no version.");
                }

                $version = (string) $version;

                if (str_starts_with($version, 'link:')) {
                    continue;
                }

                $keys[] = match (true) {
                    $major >= 9 => array_key_exists($version, $graph) && ! array_key_exists("{$name}@{$version}", $graph) ? $version : "{$name}@{$version}",
                    array_key_exists($version, $graph), preg_match('/^\d/', $version) !== 1 => $version,
                    $major === 6 => "/{$name}@{$version}",
                    default => "/{$name}/{$version}",
                };
            }
        }

        return $keys;
    }

    /**
     * Name and registry version of a package or snapshot key, without the peers or patch. Keys of other sources
     * (`demo@file:…`, `github.com/…`) give null.
     *
     * @return array{string, string}|null
     */
    private function nameAndVersion(string $key, int $major): ?array
    {
        if ($major < 9) {
            if (! str_starts_with($key, '/')) {
                return null;
            }

            $key = substr($key, 1);
        }

        if ($major === 5) {
            $segments = explode('/', $key);
            $nameLength = str_starts_with($key, '@') ? 2 : 1;
            $name = implode('/', array_slice($segments, 0, $nameLength));
            $version = explode('_', $segments[$nameLength] ?? '', 2)[0];
        } else {
            $key = $this->withoutPeersOrPatch($key);
            $separator = strpos($key, '@', 1);

            if ($separator === false) {
                return null;
            }

            $name = substr($key, 0, $separator);
            $version = substr($key, $separator + 1);
        }

        return $name !== '' && preg_match('/^\d[^\s:\/]*$/', $version) === 1 ? [$name, $version] : null;
    }

    /**
     * A 6.x or 9.x key without the peers or patch appended to it, which may nest:
     * `@inertiajs/react@2.3.29(debug@4.4.3(supports-color@7.2.0))(react@19.3.1)`. A local path may itself hold
     * parentheses, so only the groups at the end are removed.
     */
    private function withoutPeersOrPatch(string $key): string
    {
        while (str_ends_with($key, ')')) {
            $depth = 0;

            for ($position = strlen($key) - 1; $position > 0; $position--) {
                $depth += match ($key[$position]) {
                    ')' => 1,
                    '(' => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    break;
                }
            }

            if ($depth !== 0) {
                return $key;
            }

            $key = substr($key, 0, $position);
        }

        return $key;
    }
}

<?php

namespace App\Services\Lockfiles;

use App\Enums\Ecosystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * yarn.lock of Yarn 1 ("classic", its own format under a `# yarn lockfile v1` header) and of Yarn 3 and 4 (YAML with
 * `__metadata`). Each entry is one resolved package, keyed by the comma-separated ranges that resolve to it, e.g.
 * `ms@2.1.3, ms@^2.1.3` or `"lodash-legacy@npm:lodash@4.17.20"` for an alias.
 *
 * yarn.lock does not say which packages are only installed for development (a workspace entry of Yarn 3 and 4 lists
 * its dependencies and devDependencies together), so every package counts as production: `isDev = false` means "not
 * known to be dev-only".
 *
 * Left out: workspaces, and packages from git, a tarball URL or a local path, which OSV cannot know. A package Yarn
 * patches (`patch:`) is kept when the patched package comes from the registry.
 */
class YarnLockParser implements LockfileParser
{
    /**
     * `__metadata.version` written by Yarn 3 (6), Yarn 4.0 to 4.12 (8), 4.14 (9) and 4.16 and later (10).
     */
    private const array SUPPORTED_BERRY_VERSIONS = [6, 8, 9, 10];

    public function parse(string $content): array
    {
        if (preg_match('/^# yarn lockfile v1\R/m', $content) === 1) {
            return ComponentData::unique($this->fromClassic($content));
        }

        try {
            $lock = Yaml::parse($content);
        } catch (ParseException $exception) {
            throw new UnreadableLockfileException("Not a yarn.lock: no \"# yarn lockfile v1\" header and not valid YAML: {$exception->getMessage()}", previous: $exception);
        }

        if (! is_array($lock) || ! is_array($lock['__metadata'] ?? null)) {
            throw new UnreadableLockfileException('Not a yarn.lock: no "# yarn lockfile v1" header or "__metadata".');
        }

        $version = $lock['__metadata']['version'] ?? null;

        if (! in_array($version, self::SUPPORTED_BERRY_VERSIONS, true)) {
            throw new UnreadableLockfileException('yarn.lock version '.(is_scalar($version) ? $version : '?').' is not supported (Yarn 1, and Yarn 3 and 4 with versions '.implode(', ', self::SUPPORTED_BERRY_VERSIONS).' are).');
        }

        unset($lock['__metadata']);

        return ComponentData::unique($this->fromBerry($lock));
    }

    /**
     * Entries start at the beginning of a line with their key and a colon; their fields are indented by two spaces as
     * `version "1.2.3"` and `resolved "https://…"`. The lists under `dependencies:`, four spaces in, are not needed.
     *
     * @return list<ComponentData>
     */
    private function fromClassic(string $content): array
    {
        $entries = [];
        $entry = null;

        foreach (preg_split('/\R/', $content) ?: [] as $number => $line) {
            if (trim($line) === '' || str_starts_with($line, '#') || str_starts_with($line, '    ')) {
                continue;
            }

            if (! str_starts_with($line, ' ')) {
                if (! str_ends_with($line, ':')) {
                    throw new UnreadableLockfileException('Unexpected entry on line '.($number + 1).'.');
                }

                $entries[] = ['key' => substr($line, 0, -1), 'line' => $number + 1, 'fields' => []];
                $entry = array_key_last($entries);

                continue;
            }

            if ($entry === null || preg_match('/^  ([A-Za-z]+)(?::| "((?:[^"\\\\]|\\\\.)*)"| (\S+))$/', $line, $matches) !== 1) {
                throw new UnreadableLockfileException('Unexpected field on line '.($number + 1).'.');
            }

            $entries[$entry]['fields'][$matches[1]] = ($matches[2] ?? '') !== '' ? stripcslashes($matches[2]) : ($matches[3] ?? '');
        }

        $components = [];

        foreach ($entries as ['key' => $key, 'line' => $line, 'fields' => $fields]) {
            $descriptors = $this->descriptors($key);
            $version = $fields['version'] ?? '';
            $resolved = $fields['resolved'] ?? null;

            if ($descriptors === [] || $version === '') {
                throw new UnreadableLockfileException("Entry on line {$line} has no name or version.");
            }

            if (preg_match('/^\d/', $version) !== 1 || ($resolved !== null && preg_match('#^https?://#i', $resolved) !== 1)) {
                continue;
            }

            foreach ($descriptors as $descriptor) {
                [$name, $range] = $this->splitDescriptor($descriptor);

                if (str_starts_with($range, 'npm:')) {
                    [$name, $range] = $this->splitDescriptor(substr($range, 4));
                }

                if ($this->isRegistryRange($range)) {
                    $components[] = new ComponentData(Ecosystem::Npm, $name, $version);

                    break;
                }
            }
        }

        return $components;
    }

    /**
     * Name and version come from `resolution`, which names the package actually installed: `lodash@npm:4.17.20` for
     * the alias `lodash-legacy@npm:lodash@4.17.20`, `resolve@patch:resolve@npm%3A1.22.13#…` for a patched package.
     *
     * @param  array<mixed>  $lock
     * @return list<ComponentData>
     */
    private function fromBerry(array $lock): array
    {
        $components = [];

        foreach ($lock as $key => $entry) {
            if (! is_array($entry) || ! is_string($entry['resolution'] ?? null) || ! is_scalar($entry['version'] ?? null)) {
                throw new UnreadableLockfileException("Entry \"{$key}\" has no resolution or version.");
            }

            [$name, $reference] = $this->splitDescriptor($entry['resolution']);
            $version = (string) $entry['version'];

            if (str_starts_with($reference, 'patch:')) {
                $reference = $this->splitDescriptor(rawurldecode(explode('#', substr($reference, 6), 2)[0]))[1];
            }

            if (str_starts_with($reference, 'npm:') && preg_match('/^\d/', $version) === 1) {
                $components[] = new ComponentData(Ecosystem::Npm, $name, $version);
            }
        }

        return $components;
    }

    /**
     * The comma-separated descriptors of a Yarn 1 key, unquoted: `"@babel/core@^7.0.0", "@babel/core@^7.1"`. Yarn
     * groups every range resolved to the same package, so a registry range may follow a tarball URL.
     *
     * @return list<string>
     */
    private function descriptors(string $key): array
    {
        preg_match_all('/\G\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^,"]+?))\s*(?:,|$)/', $key, $matches, PREG_SET_ORDER);

        return array_values(array_filter(array_map(
            fn (array $match): string => isset($match[2]) ? trim($match[2]) : stripcslashes($match[1]),
            $matches,
        ), fn (string $descriptor): bool => $descriptor !== ''));
    }

    /**
     * `@scope/name@range` → [`@scope/name`, `range`]. The range is empty when the descriptor has none.
     *
     * @return array{string, string}
     */
    private function splitDescriptor(string $descriptor): array
    {
        $separator = strpos($descriptor, '@', 1);

        return $separator === false ? [$descriptor, ''] : [substr($descriptor, 0, $separator), substr($descriptor, $separator + 1)];
    }

    /**
     * A semver range or dist-tag. Git, tarball URLs, local paths and GitHub shorthands (`user/repo`) carry a `:` or a
     * `/`, which a registry range never does.
     */
    private function isRegistryRange(string $range): bool
    {
        return ! str_contains($range, ':') && ! str_contains($range, '/');
    }
}

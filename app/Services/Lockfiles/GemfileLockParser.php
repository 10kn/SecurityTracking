<?php

namespace App\Services\Lockfiles;

use App\Enums\Ecosystem;

/**
 * Gemfile.lock: the gems under each `GEM` source's `specs:`, four spaces in, as `name (version)`. A native gem's
 * version carries its platform (`1.16.2-x86_64-linux`), which is moved to the package URL so OSV gets the plain
 * version. Gems from `GIT` and `PATH` sources are not on rubygems.org, so they are left out.
 *
 * Bundler does not record Gemfile groups in the lockfile, so every gem counts as a production dependency.
 */
class GemfileLockParser implements LockfileParser
{
    public function parse(string $content): array
    {
        $section = null;
        $inSpecs = false;
        $components = [];

        foreach (preg_split('/\R/', $content) ?: [] as $number => $line) {
            $line = rtrim($line);

            if (preg_match('/^[A-Z][A-Z ]*$/', $line) === 1) {
                $section = $line;
                $inSpecs = false;

                continue;
            }

            if ($section !== 'GEM' || $line === '' || str_starts_with($line, '      ')) {
                continue;
            }

            if ($line === '  specs:') {
                $inSpecs = true;

                continue;
            }

            if (! $inSpecs || ! str_starts_with($line, '    ')) {
                continue;
            }

            if (preg_match('/^    ([A-Za-z0-9._-]+) \((\d[^)\s]*)\)$/', $line, $matches) !== 1) {
                throw new UnreadableLockfileException('Unexpected gem spec on line '.($number + 1).'.');
            }

            [$version, $platform] = array_pad(explode('-', $matches[2], 2), 2, null);

            $components[] = new ComponentData(Ecosystem::Rubygems, $matches[1], (string) $version, qualifiers: $platform === null ? [] : ['platform' => $platform]);
        }

        if ($section === null) {
            throw new UnreadableLockfileException('No Gemfile.lock sections such as GEM or DEPENDENCIES.');
        }

        return ComponentData::unique($components);
    }
}

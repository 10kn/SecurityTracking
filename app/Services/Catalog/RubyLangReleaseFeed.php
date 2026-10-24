<?php

namespace App\Services\Catalog;

use App\Models\Technology;

/**
 * https://cache.ruby-lang.org/pub/ruby/index.txt lists every Ruby tarball. It does not say which releases are security
 * fixes, so `isSecurityRelease` stays null. Previews and release candidates are skipped.
 */
class RubyLangReleaseFeed implements ReleaseFeed
{
    public const string URL = 'https://cache.ruby-lang.org/pub/ruby/index.txt';

    public function releases(Technology $technology, array $branches): array
    {
        $versions = [];

        foreach (preg_split('/\R/', CatalogHttp::client()->get(self::URL)->body()) ?: [] as $line) {
            if (preg_match('/^ruby-(\d+\.\d+\.\d+)\t/', $line, $matches) === 1) {
                $versions[$matches[1]] = true;
            }
        }

        return array_map(fn (string $version): ReleaseData => new ReleaseData($version), array_keys($versions));
    }
}

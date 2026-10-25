<?php

namespace App\Services\Catalog;

use App\Enums\Technology;
use Carbon\CarbonImmutable;

/**
 * https://nodejs.org/dist/index.json lists every Node.js release with a `security` flag.
 */
class NodejsReleaseFeed implements ReleaseFeed
{
    public const string URL = 'https://nodejs.org/dist/index.json';

    public function releases(Technology $technology, array $branches): array
    {
        /** @var list<array<string, mixed>> $releases */
        $releases = CatalogHttp::client()->get(self::URL)->json() ?? [];

        return array_map(fn (array $release): ReleaseData => new ReleaseData(
            version: ltrim((string) $release['version'], 'v'),
            isSecurityRelease: (bool) ($release['security'] ?? false),
            releasedAt: isset($release['date']) ? CarbonImmutable::parse($release['date']) : null,
        ), $releases);
    }
}

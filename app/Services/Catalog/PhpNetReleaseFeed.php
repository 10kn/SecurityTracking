<?php

namespace App\Services\Catalog;

use App\Models\Technology;
use Carbon\CarbonImmutable;

/**
 * https://www.php.net/releases/?json lists every PHP release of a major version, tagged "security" when it fixes vulnerabilities.
 */
class PhpNetReleaseFeed implements ReleaseFeed
{
    public const string URL = 'https://www.php.net/releases/';

    public function releases(Technology $technology, array $branches): array
    {
        $majors = array_values(array_unique(array_map(fn (BranchData $branch): string => explode('.', $branch->branch)[0], $branches)));

        $releases = [];

        foreach ($majors as $major) {
            /** @var array<string, array<string, mixed>> $versions */
            $versions = CatalogHttp::client()->get(self::URL, ['json' => '', 'version' => $major, 'max' => 1000])->json() ?? [];

            foreach ($versions as $version => $release) {
                $releases[] = new ReleaseData(
                    version: (string) $version,
                    isSecurityRelease: in_array('security', $release['tags'] ?? [], true),
                    releasedAt: isset($release['date']) ? CarbonImmutable::parse($release['date']) : null,
                );
            }
        }

        return $releases;
    }
}

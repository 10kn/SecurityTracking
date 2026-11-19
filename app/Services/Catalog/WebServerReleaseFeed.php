<?php

namespace App\Services\Catalog;

use App\Enums\Technology;
use UnexpectedValueException;

/** Published source tarballs, with security releases identified by the vendor's advisory data. */
class WebServerReleaseFeed implements ReleaseFeed
{
    public const string NGINX_ARCHIVE = 'https://nginx.org/download/';

    public const string APACHE_ARCHIVE = 'https://archive.apache.org/dist/httpd/';

    public function releases(Technology $technology, array $branches): array
    {
        $archive = CatalogHttp::client()->get($technology->releaseSourceUrl())->body();
        $name = $technology === Technology::Nginx ? 'nginx' : '(?:httpd|apache)';
        preg_match_all('/href=["\']'.$name.'[-_](\d+\.\d+\.\d+)\.tar\.gz["\']/i', $archive, $matches);
        $versions = array_values(array_unique($matches[1]));

        if ($versions === []) {
            throw new UnexpectedValueException('The web server archive does not list published releases.');
        }

        $securityReleases = [];
        foreach (app($technology->noticeFeed())->entries() as $entry) {
            foreach ($entry->vendorPatchVersions as $version) {
                $securityReleases[$version] = true;
            }
        }

        return array_map(fn (string $version): ReleaseData => new ReleaseData(
            version: $version,
            isSecurityRelease: isset($securityReleases[$version]),
        ), $versions);
    }

    public static function flagsSecurityReleases(): bool
    {
        return true;
    }
}

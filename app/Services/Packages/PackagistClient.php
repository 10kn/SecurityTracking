<?php

namespace App\Services\Packages;

use App\Services\Catalog\CatalogHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

/**
 * Reads the security advisories API of Packagist (https://packagist.org/apidoc#list-security-advisories), the source
 * `composer audit` reads: GitHub's advisories merged with FriendsOfPHP/security-advisories.
 */
class PackagistClient
{
    /**
     * Every advisory of each package, whatever versions it affects, in batches of `psm.packages.packagist_batch_size`
     * package names.
     *
     * @param  list<string>  $names
     * @return array<string, list<array<string, mixed>>> By package name; packages without advisories are left out.
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException When the response is not a list of advisories by package.
     */
    public function advisories(array $names): array
    {
        $advisories = [];

        foreach (array_chunk($names, max(1, (int) config('psm.packages.packagist_batch_size'))) as $batch) {
            $listed = CatalogHttp::client(config('psm.packages.http_timeout_seconds'))
                ->asForm()
                ->post(rtrim((string) config('psm.packages.packagist_url'), '/').'/api/security-advisories/', ['packages' => $batch])
                ->json('advisories');

            if (! is_array($listed)) {
                throw new UnexpectedValueException('Packagist did not return a list of security advisories.');
            }

            foreach ($listed as $name => $packageAdvisories) {
                $packageAdvisories = array_values(array_filter((array) $packageAdvisories, is_array(...)));

                if ($packageAdvisories !== []) {
                    $advisories[(string) $name] = $packageAdvisories;
                }
            }
        }

        return $advisories;
    }
}

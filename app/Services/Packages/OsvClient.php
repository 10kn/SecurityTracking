<?php

namespace App\Services\Packages;

use App\Services\Catalog\CatalogHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

/**
 * Reads the OSV API (https://google.github.io/osv.dev/api/): `querybatch` lists the vulnerability ids of many package
 * versions at once, `vulns/{id}` returns one vulnerability in full.
 */
class OsvClient
{
    /**
     * A query that keeps paging is cut off instead of looping forever.
     */
    private const int MAX_PAGES = 50;

    /**
     * The ids and modification times of the vulnerabilities of each package version, in batches of
     * `psm.packages.batch_size`, following `next_page_token` until every page is read.
     *
     * @param  list<PackageVersion>  $packages
     * @return array<string, list<array{id: string, modified: string}>> By PackageVersion::key(); packages without
     *                                                                  vulnerabilities are left out.
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException When the response does not answer every query.
     */
    public function query(array $packages): array
    {
        $vulnerabilities = [];

        foreach (array_chunk($packages, max(1, (int) config('psm.packages.batch_size'))) as $batch) {
            $pending = array_map(fn (PackageVersion $package): array => [$package, null], $batch);

            for ($page = 1; $pending !== []; $page++) {
                if ($page > self::MAX_PAGES) {
                    throw new UnexpectedValueException('OSV kept returning next_page_token after '.self::MAX_PAGES.' pages.');
                }

                $results = $this->http()->post($this->url('querybatch'), ['queries' => array_map(
                    fn (array $query): array => [
                        'package' => ['ecosystem' => $query[0]->ecosystem->osvName(), 'name' => $query[0]->name],
                        'version' => $query[0]->version,
                        ...($query[1] === null ? [] : ['page_token' => $query[1]]),
                    ],
                    $pending,
                )])->json('results');

                if (! is_array($results) || count($results) !== count($pending)) {
                    throw new UnexpectedValueException('OSV querybatch did not return one result per query.');
                }

                $next = [];

                foreach (array_values($results) as $index => $result) {
                    $package = $pending[$index][0];

                    foreach ((array) ($result['vulns'] ?? []) as $vulnerability) {
                        $vulnerabilities[$package->key()][] = ['id' => (string) $vulnerability['id'], 'modified' => (string) ($vulnerability['modified'] ?? '')];
                    }

                    if (filled($result['next_page_token'] ?? null)) {
                        $next[] = [$package, (string) $result['next_page_token']];
                    }
                }

                $pending = $next;
            }
        }

        return $vulnerabilities;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException When the response is not a vulnerability.
     */
    public function vulnerability(string $id): array
    {
        $vulnerability = $this->http()->get($this->url('vulns/'.rawurlencode($id)))->json();

        if (! is_array($vulnerability) || ($vulnerability['id'] ?? null) !== $id) {
            throw new UnexpectedValueException("OSV did not return vulnerability {$id}.");
        }

        return $vulnerability;
    }

    private function http(): PendingRequest
    {
        return CatalogHttp::client(config('psm.packages.http_timeout_seconds'));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('psm.packages.osv_url'), '/').'/'.$path;
    }
}

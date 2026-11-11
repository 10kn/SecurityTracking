<?php

namespace App\Services\Packages;

use App\Enums\Ecosystem;
use App\Services\Catalog\CatalogHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;
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
        return $this->batch(collect($packages)->mapWithKeys(fn (PackageVersion $package): array => [$package->key() => [
            'package' => ['ecosystem' => $package->ecosystem->osvName(), 'name' => $package->name],
            'version' => $package->version,
        ]])->all());
    }

    /**
     * The ids and modification times of every vulnerability of each package, whatever the version: for ecosystems whose
     * versions OSV cannot compare (GitHub Actions), where the caller compares them itself.
     *
     * @param  list<string>  $names
     * @return array<string, list<array{id: string, modified: string}>> By name; packages without vulnerabilities are
     *                                                                  left out.
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException When the response does not answer every query.
     */
    public function vulnerabilitiesOfPackages(Ecosystem $ecosystem, array $names): array
    {
        return $this->batch(collect($names)->unique()->mapWithKeys(fn (string $name): array => [$name => [
            'package' => ['ecosystem' => $ecosystem->osvName(), 'name' => $name],
        ]])->all());
    }

    /**
     * Sends the queries in batches of `psm.packages.batch_size`, following `next_page_token` until every page is read.
     *
     * @param  array<string, array<string, mixed>>  $queries  By the key results are returned under.
     * @return array<string, list<array{id: string, modified: string}>>
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    private function batch(array $queries): array
    {
        $vulnerabilities = [];

        foreach (array_chunk($queries, max(1, (int) config('psm.packages.batch_size')), preserve_keys: true) as $batch) {
            $pending = array_map(fn (string $key): array => [$key, null], array_keys($batch));

            for ($page = 1; $pending !== []; $page++) {
                if ($page > self::MAX_PAGES) {
                    throw new UnexpectedValueException('OSV kept returning next_page_token after '.self::MAX_PAGES.' pages.');
                }

                $results = $this->http()->post($this->url('querybatch'), ['queries' => array_map(
                    fn (array $query): array => [...$batch[$query[0]], ...($query[1] === null ? [] : ['page_token' => $query[1]])],
                    $pending,
                )])->json('results');

                if (! is_array($results) || count($results) !== count($pending)) {
                    throw new UnexpectedValueException('OSV querybatch did not return one result per query.');
                }

                $next = [];

                foreach (array_values($results) as $index => $result) {
                    $key = $pending[$index][0];

                    foreach ((array) ($result['vulns'] ?? []) as $vulnerability) {
                        $vulnerabilities[$key][] = ['id' => (string) $vulnerability['id'], 'modified' => (string) ($vulnerability['modified'] ?? '')];
                    }

                    if (filled($result['next_page_token'] ?? null)) {
                        $next[] = [$key, (string) $result['next_page_token']];
                    }
                }

                $pending = $next;
            }
        }

        return $vulnerabilities;
    }

    /**
     * Several vulnerabilities in full, `psm.packages.concurrency` requests at a time: a first scan of a large lockfile
     * fetches hundreds of them, too slow one by one for a queued job.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>> By id.
     *
     * @throws Throwable The error of the first request that failed after its retries.
     * @throws UnexpectedValueException When a response is not the vulnerability asked for.
     */
    public function vulnerabilities(array $ids): array
    {
        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (string $id) => CatalogHttp::configure($pool->as($id), config('psm.packages.http_timeout_seconds'))->get($this->url('vulns/'.rawurlencode($id))),
            $ids,
        ), concurrency: max(1, (int) config('psm.packages.concurrency')));

        $vulnerabilities = [];

        foreach ($ids as $id) {
            $response = $responses[$id] ?? null;

            if ($response instanceof Throwable) {
                throw $response;
            }

            $vulnerability = $response instanceof Response ? $response->json() : null;

            if (! is_array($vulnerability) || ($vulnerability['id'] ?? null) !== $id) {
                throw new UnexpectedValueException("OSV did not return vulnerability {$id}.");
            }

            $vulnerabilities[$id] = $vulnerability;
        }

        return $vulnerabilities;
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

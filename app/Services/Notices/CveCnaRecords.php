<?php

namespace App\Services\Notices;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class CveCnaRecords
{
    public const string URL = 'https://raw.githubusercontent.com/CVEProject/cvelistV5/main/cves';

    public const string PRIORITY_VERSION = '4.0';

    /**
     * Only the assigning CNA's metrics are read. A failed lookup leaves the previously stored record in place.
     *
     * @param  list<string>  $ids
     * @return array<string, array{provider: string, url: string, published_at: ?string, scores: array<string, float>}>
     */
    public function forIds(array $ids): array
    {
        $records = [];
        $missing = [];

        foreach (array_unique($ids) as $id) {
            if (preg_match('/^CVE-(\d{4})-(\d{4,})$/D', $id, $match) !== 1) {
                continue;
            }

            $cached = Cache::get('notice-cna:'.$id);

            if ($cached === null) {
                $bucket = substr($match[2], 0, -3).'xxx';
                $missing[$id] = self::URL."/{$match[1]}/{$bucket}/{$id}.json";
            } elseif ($cached !== false) {
                $records[$id] = $cached;
            }
        }

        if ($missing === []) {
            return $records;
        }

        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (string $id, string $url) => $pool->as($id)->acceptJson()->connectTimeout(2)->timeout(5)->get($url),
            array_keys($missing),
            array_values($missing),
        ), concurrency: 8);

        foreach ($missing as $id => $url) {
            $response = $responses[$id] ?? null;
            $record = $response instanceof Response && $response->successful() ? $this->record($response, $id, $url) : null;

            Cache::put('notice-cna:'.$id, $record ?? false, isset($record['scores'][self::PRIORITY_VERSION]) ? now()->addHours(6) : now()->addMinutes(10));

            if ($record !== null) {
                $records[$id] = $record;
            }
        }

        return $records;
    }

    /** @return array{provider: string, url: string, published_at: ?string, scores: array<string, float>}|null */
    private function record(Response $response, string $id, string $url): ?array
    {
        $metadata = $response->json('cveMetadata');
        $cna = $response->json('containers.cna');

        if (! is_array($metadata) || ! is_array($cna) || ($metadata['cveId'] ?? null) !== $id
            || ($metadata['state'] ?? null) !== 'PUBLISHED') {
            return null;
        }

        $provider = $cna['providerMetadata']['shortName'] ?? $metadata['assignerShortName'] ?? null;

        if (! is_string($provider) || $provider === '') {
            return null;
        }

        $scores = [];

        foreach ((array) ($cna['metrics'] ?? []) as $metric) {
            foreach (['3.1' => 'cvssV3_1', '4.0' => 'cvssV4_0'] as $version => $key) {
                $score = $metric[$key]['baseScore'] ?? null;

                if ((is_float($score) || is_int($score)) && $score >= 0 && $score <= 10) {
                    $scores[$version] = max($scores[$version] ?? 0.0, (float) $score);
                }
            }
        }

        $published = $cna['datePublic'] ?? $metadata['datePublished'] ?? null;

        return [
            'provider' => $provider,
            'url' => $url,
            'published_at' => is_string($published) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $published) === 1
                && ($timestamp = strtotime($published)) !== false
                ? CarbonImmutable::createFromTimestampUTC($timestamp)->toIso8601String()
                : null,
            'scores' => $scores,
        ];
    }
}

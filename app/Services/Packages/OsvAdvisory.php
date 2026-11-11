<?php

namespace App\Services\Packages;

use App\Enums\AdvisoryKind;
use App\Enums\Ecosystem;
use App\Enums\Severity;
use App\Support\Cvss3;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Turns an OSV vulnerability (https://ossf.github.io/osv-schema/) into the attributes of an Advisory.
 */
final class OsvAdvisory
{
    /**
     * GitHub's ratings in `database_specific.severity`.
     *
     * @var array<string, Severity>
     */
    private const array GITHUB_SEVERITIES = [
        'CRITICAL' => Severity::Critical,
        'HIGH' => Severity::High,
        'MODERATE' => Severity::Medium,
        'LOW' => Severity::Low,
    ];

    /**
     * @param  array<string, mixed>  $vulnerability
     * @return array<string, mixed>
     */
    public static function attributes(array $vulnerability): array
    {
        $id = (string) $vulnerability['id'];
        [$severity, $cvssScore] = self::severity($vulnerability);
        $details = trim((string) ($vulnerability['details'] ?? ''));

        return [
            'kind' => AdvisoryKind::Vulnerability,
            'aliases' => array_values(array_filter((array) ($vulnerability['aliases'] ?? []), is_string(...))),
            'title' => Str::limit(filled($vulnerability['summary'] ?? null) ? trim((string) $vulnerability['summary']) : $id, 250),
            'summary' => $details === '' ? null : Str::limit($details, config('psm.packages.summary_length')),
            'severity' => $severity,
            'cvss_score' => $cvssScore,
            'url' => 'https://osv.dev/vulnerability/'.rawurlencode($id),
            'published_at' => self::date($vulnerability['published'] ?? null),
            'source_modified_at' => self::date($vulnerability['modified'] ?? null),
            'withdrawn_at' => self::date($vulnerability['withdrawn'] ?? null),
            'fixed_versions' => self::fixedVersions($vulnerability),
            'affected_ranges' => self::affectedRanges($vulnerability),
        ];
    }

    /**
     * GitHub's rating when the advisory has one, otherwise the rating of the CVSS v3 base score, otherwise unknown.
     * The CVSS v3 score is kept whenever there is a vector.
     *
     * @param  array<string, mixed>  $vulnerability
     * @return array{0: Severity, 1: float|null}
     */
    public static function severity(array $vulnerability): array
    {
        $cvssScore = null;

        foreach ((array) ($vulnerability['severity'] ?? []) as $rating) {
            if (is_array($rating) && ($rating['type'] ?? null) === 'CVSS_V3' && is_string($rating['score'] ?? null)) {
                $cvssScore = Cvss3::baseScore($rating['score']);

                if ($cvssScore !== null) {
                    break;
                }
            }
        }

        $githubSeverity = self::GITHUB_SEVERITIES[strtoupper((string) ($vulnerability['database_specific']['severity'] ?? ''))] ?? null;

        return [$githubSeverity ?? ($cvssScore === null ? Severity::Unknown : Cvss3::severity($cvssScore)), $cvssScore];
    }

    /**
     * The `fixed` events of the ecosystems MVP scans, by "{OSV ecosystem}:{package name}". Git commit ranges are
     * left out.
     *
     * @param  array<string, mixed>  $vulnerability
     * @return array<string, list<string>>
     */
    public static function fixedVersions(array $vulnerability): array
    {
        $fixed = [];

        foreach ((array) ($vulnerability['affected'] ?? []) as $affected) {
            $ecosystem = Ecosystem::fromOsvName((string) ($affected['package']['ecosystem'] ?? ''));
            $name = $affected['package']['name'] ?? null;

            if ($ecosystem === null || ! is_string($name)) {
                continue;
            }

            foreach ((array) ($affected['ranges'] ?? []) as $range) {
                if (! in_array($range['type'] ?? null, ['ECOSYSTEM', 'SEMVER'], true)) {
                    continue;
                }

                foreach ((array) ($range['events'] ?? []) as $event) {
                    if (is_string($event['fixed'] ?? null)) {
                        $fixed[self::key($ecosystem, $name)][] = $event['fixed'];
                    }
                }
            }
        }

        return array_map(fn (array $versions): array => array_values(array_unique($versions)), $fixed);
    }

    /**
     * The version ranges and versions listed as affected, of the ecosystems MVP scans, by "{OSV ecosystem}:{package
     * name}": what affects() reads where OSV cannot match versions itself (GitHub Actions). Git commit ranges are left
     * out.
     *
     * @param  array<string, mixed>  $vulnerability
     * @return array<string, array{ranges: list<list<array<string, string>>>, versions: list<string>}>
     */
    public static function affectedRanges(array $vulnerability): array
    {
        $affectedRanges = [];

        foreach ((array) ($vulnerability['affected'] ?? []) as $affected) {
            $ecosystem = Ecosystem::fromOsvName((string) ($affected['package']['ecosystem'] ?? ''));
            $name = $affected['package']['name'] ?? null;

            if ($ecosystem === null || ! is_string($name)) {
                continue;
            }

            $key = self::key($ecosystem, $name);
            $affectedRanges[$key] ??= ['ranges' => [], 'versions' => []];

            foreach ((array) ($affected['ranges'] ?? []) as $range) {
                if (! in_array($range['type'] ?? null, ['ECOSYSTEM', 'SEMVER'], true)) {
                    continue;
                }

                $events = collect((array) ($range['events'] ?? []))
                    ->map(fn (mixed $event): array => collect(is_array($event) ? $event : [])
                        ->only(['introduced', 'fixed', 'last_affected'])
                        ->filter(fn (mixed $value): bool => is_string($value))
                        ->all())
                    ->filter()
                    ->values()
                    ->all();

                if ($events !== []) {
                    $affectedRanges[$key]['ranges'][] = $events;
                }
            }

            foreach ((array) ($affected['versions'] ?? []) as $version) {
                if (is_string($version)) {
                    $affectedRanges[$key]['versions'][] = $version;
                }
            }
        }

        return $affectedRanges;
    }

    /**
     * Whether $version of the package is affected, read from affectedRanges() (OSV semantics: a version is affected
     * from an `introduced` event on, until a `fixed` event at or below it or a `last_affected` event below it).
     * Versions compare without a leading "v".
     *
     * @param  array<string, array{ranges?: list<list<array<string, string>>>, versions?: list<string>}>  $affectedRanges
     */
    public static function affects(array $affectedRanges, Ecosystem $ecosystem, string $name, string $version): bool
    {
        $affected = $affectedRanges[self::key($ecosystem, $name)] ?? null;

        if ($affected === null) {
            return false;
        }

        $comparable = fn (string $value): string => ltrim($value, 'vV');
        $version = $comparable($version);

        if (in_array($version, array_map($comparable, $affected['versions'] ?? []), true)) {
            return true;
        }

        foreach ($affected['ranges'] ?? [] as $events) {
            $isAffected = false;

            $sorted = collect($events)
                ->map(fn (array $event): array => ['type' => (string) array_key_first($event), 'version' => $comparable((string) reset($event))])
                ->sort(fn (array $a, array $b): int => version_compare($a['version'], $b['version']));

            foreach ($sorted as ['type' => $type, 'version' => $eventVersion]) {
                $comparison = version_compare($eventVersion, $version);

                if ($type === 'introduced' && $comparison <= 0) {
                    $isAffected = true;
                } elseif (($type === 'fixed' && $comparison <= 0) || ($type === 'last_affected' && $comparison < 0)) {
                    $isAffected = false;
                }
            }

            if ($isAffected) {
                return true;
            }
        }

        return false;
    }

    /**
     * E.g. "npm:lodash"; GitHub Actions are named in lower case, as GitHub matches them.
     */
    private static function key(Ecosystem $ecosystem, string $name): string
    {
        return $ecosystem->osvName().':'.($ecosystem === Ecosystem::GitHubActions ? strtolower($name) : $name);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}

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
                        $fixed["{$ecosystem->osvName()}:{$name}"][] = $event['fixed'];
                    }
                }
            }
        }

        return array_map(fn (array $versions): array => array_values(array_unique($versions)), $fixed);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}

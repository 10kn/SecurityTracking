<?php

namespace App\Services\Packages;

use App\Enums\AdvisoryKind;
use App\Enums\Ecosystem;
use App\Enums\Severity;
use App\Support\Cvss3;
use App\Support\GemVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Turns a RubySec advisory (https://github.com/rubysec/ruby-advisory-db#schema) into the attributes of an Advisory, and
 * tells which versions it affects the way `bundler-audit` does: those in none of `patched_versions` and none of
 * `unaffected_versions`.
 */
final class RubySecAdvisory
{
    /**
     * The same advisory is filed under each gem it affects, e.g. CVE-2016-6316 under actionpack and actionview: the
     * first gem's file gives the text, every gem its fixed versions.
     *
     * @param  array<string, array<string, mixed>>  $byGem  The advisory's file under each gem, by gem name.
     * @return array<string, mixed>
     */
    public static function attributes(string $id, array $byGem): array
    {
        ksort($byGem);
        $gem = array_key_first($byGem);
        $advisory = $byGem[$gem];
        [$severity, $cvssScore] = self::severity($advisory);
        $description = trim((string) ($advisory['description'] ?? ''));

        return [
            'kind' => AdvisoryKind::Vulnerability,
            'aliases' => self::aliases($advisory),
            'title' => Str::limit(filled($advisory['title'] ?? null) ? trim((string) $advisory['title']) : $id, 250),
            'summary' => $description === '' ? null : Str::limit($description, config('psm.packages.summary_length')),
            'severity' => $severity,
            'cvss_score' => $cvssScore,
            'url' => filled($advisory['url'] ?? null) ? (string) $advisory['url'] : "https://github.com/rubysec/ruby-advisory-db/blob/master/gems/{$gem}/{$id}.yml",
            'published_at' => self::date($advisory['date'] ?? null),
            'source_modified_at' => null,
            'withdrawn_at' => null,
            'fixed_versions' => collect($byGem)
                ->mapWithKeys(fn (array $advisory, string $gem): array => [Ecosystem::Rubygems->osvName().":{$gem}" => self::fixedVersions($advisory)])
                ->all(),
        ];
    }

    /**
     * The GitHub id, the CVE and the OSVDB id the advisory lists: Advisory::reference() shows the first one.
     *
     * @param  array<string, mixed>  $advisory
     * @return list<string>
     */
    public static function aliases(array $advisory): array
    {
        $aliases = [];

        foreach (['ghsa' => 'GHSA-', 'cve' => 'CVE-', 'osvdb' => 'OSVDB-'] as $field => $prefix) {
            if (filled($advisory[$field] ?? null)) {
                $aliases[] = $prefix.$advisory[$field];
            }
        }

        return $aliases;
    }

    /**
     * Rated by the newest CVSS score the advisory has, as bundler-audit does: v4 and v3 on the same scale, v2 with
     * nothing above High.
     *
     * @param  array<string, mixed>  $advisory
     * @return array{0: Severity, 1: float|null}
     */
    public static function severity(array $advisory): array
    {
        foreach (['cvss_v4', 'cvss_v3'] as $field) {
            if (is_numeric($advisory[$field] ?? null)) {
                return [Cvss3::severity((float) $advisory[$field]), (float) $advisory[$field]];
            }
        }

        if (is_numeric($advisory['cvss_v2'] ?? null)) {
            $score = (float) $advisory['cvss_v2'];

            return [$score >= 7.0 ? Severity::High : Cvss3::severity($score), $score];
        }

        return [Severity::Unknown, null];
    }

    /**
     * Whether the installed version is in none of the patched and unaffected requirements. A version RubyGems cannot
     * read is matched by no advisory; a requirement it cannot read rules nothing out.
     *
     * @param  array<string, mixed>  $advisory
     */
    public static function affects(array $advisory, string $version): bool
    {
        if (! GemVersion::isValid($version)) {
            return false;
        }

        foreach ([...(array) ($advisory['patched_versions'] ?? []), ...(array) ($advisory['unaffected_versions'] ?? [])] as $requirement) {
            try {
                if (GemVersion::satisfies($version, (string) $requirement)) {
                    return false;
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return true;
    }

    /**
     * The lowest version of each patched requirement: "~> 6.1.7, >= 6.1.7.1" is fixed by 6.1.7.1.
     *
     * @param  array<string, mixed>  $advisory
     * @return list<string>
     */
    public static function fixedVersions(array $advisory): array
    {
        $fixed = [];

        foreach ((array) ($advisory['patched_versions'] ?? []) as $requirement) {
            try {
                $fixed[] = GemVersion::lowest((string) $requirement);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return array_values(array_unique(array_filter($fixed, is_string(...))));
    }

    /**
     * RubySec dates are plain dates, which YAML reads as a Unix timestamp.
     */
    private static function date(mixed $value): ?CarbonImmutable
    {
        return match (true) {
            is_int($value) => CarbonImmutable::createFromTimestampUTC($value),
            is_string($value) && $value !== '' => CarbonImmutable::parse($value, 'UTC'),
            default => null,
        };
    }
}

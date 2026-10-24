<?php

namespace App\Services\Packages;

use App\Enums\AdvisoryKind;
use App\Enums\Ecosystem;
use App\Enums\Severity;
use Carbon\CarbonImmutable;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Turns a Packagist security advisory into the attributes of an Advisory, and tells which versions it affects the way
 * `composer audit` does: the installed version matched against the `affectedVersions` constraint.
 */
final class PackagistAdvisory
{
    /**
     * @var array<string, Severity>
     */
    private const array SEVERITIES = [
        'critical' => Severity::Critical,
        'high' => Severity::High,
        'medium' => Severity::Medium,
        'low' => Severity::Low,
    ];

    /**
     * Advisories that only FriendsOfPHP lists come without a severity and stay unknown, so they reach the team in the
     * daily digest rather than right away.
     *
     * @param  array<string, mixed>  $advisory
     * @return array<string, mixed>
     */
    public static function attributes(array $advisory): array
    {
        $id = (string) $advisory['advisoryId'];
        $name = (string) $advisory['packageName'];

        return [
            'kind' => AdvisoryKind::Vulnerability,
            'aliases' => self::aliases($advisory),
            'title' => Str::limit(filled($advisory['title'] ?? null) ? trim((string) $advisory['title']) : $id, 250),
            'summary' => null,
            'severity' => self::SEVERITIES[strtolower((string) ($advisory['severity'] ?? ''))] ?? Severity::Unknown,
            'cvss_score' => null,
            'url' => filled($advisory['link'] ?? null) ? (string) $advisory['link'] : 'https://packagist.org/security-advisories/'.rawurlencode($id),
            'published_at' => self::date($advisory['reportedAt'] ?? null),
            'source_modified_at' => null,
            'withdrawn_at' => null,
            'fixed_versions' => [Ecosystem::Packagist->osvName().":{$name}" => self::fixedVersions((string) ($advisory['affectedVersions'] ?? ''))],
        ];
    }

    /**
     * The GitHub ids the advisory merges, then its CVE: Advisory::reference() shows the first one.
     *
     * @param  array<string, mixed>  $advisory
     * @return list<string>
     */
    public static function aliases(array $advisory): array
    {
        $githubIds = collect((array) ($advisory['sources'] ?? []))
            ->filter(fn (mixed $source): bool => is_array($source) && ($source['name'] ?? null) === 'GitHub' && is_string($source['remoteId'] ?? null))
            ->map(fn (array $source): string => $source['remoteId']);

        return $githubIds->push($advisory['cve'] ?? null)->filter(fn (mixed $id): bool => is_string($id) && $id !== '')->unique()->values()->all();
    }

    /**
     * Whether the installed version falls in `affectedVersions`. A version Composer cannot read (a branch such as
     * dev-main) is matched by no advisory, as in `composer audit`.
     *
     * @param  array<string, mixed>  $advisory
     */
    public static function affects(array $advisory, string $version): bool
    {
        $affected = self::constraint((string) ($advisory['affectedVersions'] ?? ''));

        try {
            return $affected !== null && $affected->matches(new Constraint('==', (new VersionParser)->normalize($version)));
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    /**
     * The upper bounds of the affected ranges that are not affected themselves: in ">=6.0,<6.1|>=6.1,<6.4.33" only
     * 6.4.33 is a fix, 6.1 still falls in the next range.
     *
     * @return list<string>
     */
    public static function fixedVersions(string $affectedVersions): array
    {
        $affected = self::constraint($affectedVersions);

        if ($affected === null || ! preg_match_all('/(?<![<>=!])<(?!=)\s*([^\s,|]+)/', $affectedVersions, $matches)) {
            return [];
        }

        $parser = new VersionParser;

        return collect($matches[1])
            ->unique()
            ->filter(function (string $bound) use ($affected, $parser): bool {
                try {
                    return ! $affected->matches(new Constraint('==', $parser->normalize($bound)));
                } catch (UnexpectedValueException) {
                    return false;
                }
            })
            ->values()
            ->all();
    }

    private static function constraint(string $affectedVersions): ?ConstraintInterface
    {
        try {
            return trim($affectedVersions) === '' ? null : (new VersionParser)->parseConstraints($affectedVersions);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC') : null;
    }
}

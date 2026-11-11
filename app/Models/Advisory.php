<?php

namespace App\Models;

use App\Enums\AdvisoryKind;
use App\Enums\Ecosystem;
use App\Enums\IncidentBasis;
use App\Enums\OperatingSystem;
use App\Enums\Severity;
use App\Enums\SeverityBasis;
use App\Enums\Technology;
use App\Services\Packages\PackagistVulnerabilityMatcher;
use App\Services\Packages\RubySecVulnerabilityMatcher;
use Database\Factories\AdvisoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['source', 'external_id', 'kind', 'incident_id', 'incident_basis', 'incident_evidence', 'aliases', 'title', 'summary', 'severity', 'severity_basis', 'cvss_score', 'technology', 'url', 'links', 'published_at', 'source_modified_at', 'withdrawn_at', 'fixed_versions', 'affected_ranges', 'content_hash', 'classified_by', 'classified_severity', 'classification_failed_at', 'is_dismissed', 'is_backfill', 'planned_release_at', 'affected_branches', 'unaffected_branches', 'platforms', 'platforms_evidence', 'classifier_revision', 'patch_versions', 'is_exploited', 'exploited_basis', 'known_exploited', 'known_exploited_at', 'severity_before_kev', 'resolved_cve_ids', 'cves_resolved_at', 'localized_summary'])]
class Advisory extends Model
{
    /** @use HasFactory<AdvisoryFactory> */
    use HasFactory;

    /**
     * The attributes an incident is derived from.
     *
     * @var list<string>
     */
    public const array INCIDENT_ATTRIBUTES = ['incident_id', 'title', 'severity', 'is_dismissed', 'is_backfill', 'published_at', 'planned_release_at', 'affected_branches', 'unaffected_branches', 'platforms', 'known_exploited'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AdvisoryKind::class,
            'incident_basis' => IncidentBasis::class,
            'incident_evidence' => 'array',
            'aliases' => 'array',
            'severity' => Severity::class,
            'severity_basis' => SeverityBasis::class,
            'cvss_score' => 'decimal:1',
            'technology' => Technology::class,
            'links' => 'array',
            'published_at' => 'datetime',
            'source_modified_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'fixed_versions' => 'array',
            'affected_ranges' => 'array',
            'classified_severity' => Severity::class,
            'classification_failed_at' => 'datetime',
            'is_dismissed' => 'boolean',
            'is_backfill' => 'boolean',
            'planned_release_at' => 'datetime',
            'affected_branches' => 'array',
            'unaffected_branches' => 'array',
            'platforms' => 'array',
            'patch_versions' => 'array',
            'is_exploited' => 'boolean',
            'exploited_basis' => 'array',
            'known_exploited' => 'array',
            'known_exploited_at' => 'datetime',
            'severity_before_kev' => Severity::class,
            'resolved_cve_ids' => 'array',
            'cves_resolved_at' => 'datetime',
        ];
    }

    /**
     * The incident of a pre-notice follows every change to it, whichever code makes it. The incident is read from the
     * database: a model loaded before an admin merged or split the incident still holds the old one, and saving it
     * does not write it back.
     */
    protected static function booted(): void
    {
        static::saved(function (Advisory $advisory): void {
            if ($advisory->kind !== AdvisoryKind::PreNotice) {
                return;
            }

            if (! $advisory->wasRecentlyCreated && ! $advisory->wasChanged(self::INCIDENT_ATTRIBUTES)) {
                return;
            }

            $current = self::whereKey($advisory->id)->value('incident_id');
            $incidentIds = [$current, $advisory->wasChanged('incident_id') ? $advisory->getOriginal('incident_id') : null];

            Incident::whereKey(array_filter($incidentIds))->get()->each->refreshFromNotices();
        });
    }

    /**
     * Pre-notices that may still alert: not dismissed, not history from a feed's first poll, and published within
     * `psm.notices.relevant_days`.
     *
     * @param  Builder<Advisory>  $query
     * @return Builder<Advisory>
     */
    #[Scope]
    protected function alertable(Builder $query): Builder
    {
        return $query->where('kind', AdvisoryKind::PreNotice)
            ->whereNotNull('technology')
            ->where('is_dismissed', false)
            ->where('is_backfill', false)
            ->where('published_at', '>=', now()->subDays(config('psm.notices.relevant_days')));
    }

    /**
     * Advisories whose title or one of whose CVE / GHSA ids matches the LIKE pattern, ignoring case: their aliases, the
     * CVE ids resolved for the GHSA ids they still list (as KnownExploitation::cveIdsOf() reads them) and the CVE ids
     * CISA KEV lists them for (only the id of each KEV entry).
     *
     * @param  Builder<Advisory>  $query
     * @return Builder<Advisory>
     */
    #[Scope]
    protected function matching(Builder $query, string $pattern): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereLike('advisories.title', $pattern)
            ->orWhereRaw(<<<'SQL'
                exists (
                    select 1 from (
                        select alias from jsonb_path_query(advisories.aliases, 'lax $[*]') as aliases (alias)
                        union all select jsonb_path_query(advisories.resolved_cve_ids -> (alias #>> '{}'), 'lax $.cves[*]') from jsonb_path_query(advisories.aliases, 'lax $[*]') as aliases (alias)
                        union all select jsonb_path_query(advisories.known_exploited, 'lax $[*].cve')
                    ) as ids (id)
                    where ids.id #>> '{}' ilike ?
                )
                SQL, [$pattern]));
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /**
     * The operating systems the pre-notice says it is limited to, with a sentence of the entry that says so (see
     * SyncNoticeFeed); empty when it states no limit, e.g. [Windows] for news about the Windows builds only.
     *
     * @return list<OperatingSystem>
     */
    public function platformLimit(): array
    {
        return collect($this->platforms ?? [])
            ->filter(fn (mixed $value): bool => is_string($value))
            ->unique()
            ->map(fn (string $value): ?OperatingSystem => OperatingSystem::tryFrom($value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Whether CISA KEV lists, or listed, one of the advisory's CVE ids (see KnownExploitation).
     */
    public function isKnownExploited(): bool
    {
        return filled($this->known_exploited);
    }

    /**
     * Critical by its CVSS score, a KEV listing or the classifier, rather than by the vendor's words alone, which only
     * pre-notices rated before the classifier became the only reader of the words have (see SeverityBasis::Vendor).
     */
    public function isCriticalBeyondWords(): bool
    {
        return $this->severity === Severity::Critical
            && ($this->severity_basis !== SeverityBasis::Vendor
                || ($this->cvss_score !== null && Severity::fromCvss((float) $this->cvss_score) === Severity::Critical)
                || $this->isKnownExploited());
    }

    /**
     * Whether the advisory is exploited: the vendor's words say so (`is_exploited`, pre-notices only) or CISA KEV
     * lists one of its CVE ids.
     */
    public function isExploited(): bool
    {
        return $this->is_exploited === true || $this->isKnownExploited();
    }

    /**
     * The id people know the advisory by, for titles and cards. The ids of Packagist (PKSA) and RubySec (the file name)
     * are only stable keys: the GitHub id, else the CVE, is shown instead.
     */
    public function reference(): string
    {
        if (! in_array($this->source, [PackagistVulnerabilityMatcher::SOURCE, RubySecVulnerabilityMatcher::SOURCE], true)) {
            return $this->external_id;
        }

        $aliases = collect($this->aliases ?? []);

        return $aliases->first(fn (string $alias): bool => str_starts_with($alias, 'GHSA-'))
            ?? $aliases->first(fn (string $alias): bool => str_starts_with($alias, 'CVE-'))
            ?? $this->external_id;
    }

    /**
     * The lowest version that fixes the advisory for the package and is newer than the installed one, read from the
     * `fixed` events the source lists. Null when no fix above the installed version is known.
     */
    public function fixedVersionFor(Ecosystem $ecosystem, string $name, string $version): ?string
    {
        $comparable = fn (string $value): string => ltrim($value, 'vV');
        $fixedVersions = array_filter((array) ($this->fixed_versions["{$ecosystem->osvName()}:{$name}"] ?? []), is_string(...));

        return collect($fixedVersions)
            ->filter(fn (string $fixed): bool => version_compare($comparable($fixed), $comparable($version), '>'))
            ->sort(fn (string $a, string $b): int => version_compare($comparable($a), $comparable($b)))
            ->first();
    }
}

<?php

namespace App\Models;

use App\Enums\IncidentScope;
use App\Enums\RuntimeStatus;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Services\Kev\KnownExploitation;
use App\Support\Version;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One security issue of a technology, told by one or more vendor pre-notices (see AssignNoticeToIncident). Its title,
 * severity, dates and branches are derived from its pre-notices that were not dismissed (see refreshFromNotices()).
 */
#[Fillable(['technology', 'title', 'severity', 'first_published_at', 'planned_release_at', 'affected_branches', 'unaffected_branches', 'merged_into_id', 'fingerprint', 'changed_at'])]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'technology' => Technology::class,
            'severity' => Severity::class,
            'first_published_at' => 'datetime',
            'planned_release_at' => 'datetime',
            'affected_branches' => 'array',
            'unaffected_branches' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    /**
     * Incidents that were not merged into another one.
     *
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    #[Scope]
    protected function current(Builder $query): Builder
    {
        return $query->whereNull('merged_into_id');
    }

    /**
     * @return HasMany<Advisory, $this>
     */
    public function notices(): HasMany
    {
        return $this->hasMany(Advisory::class);
    }

    /**
     * @return HasMany<IncidentSuggestion, $this>
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(IncidentSuggestion::class);
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'merged_into_id');
    }

    /**
     * Whether the vendor said the incident affects the branch a runtime runs. Only a branch the vendor names as affected
     * is confirmed, and only one it names as not affected is ruled out: a list of affected or fixed branches is often
     * incomplete. An end-of-life branch is never ruled out, as vendors stop assessing it, but one the vendor names as
     * affected is confirmed.
     */
    public function scopeFor(Runtime $runtime): IncidentScope
    {
        $versions = array_values(array_filter([$runtime->branch, $runtime->version ?? $runtime->raw_version], filled(...)));

        return match (true) {
            self::listsBranch($this->affected_branches ?? [], $versions) => IncidentScope::Confirmed,
            $runtime->status === RuntimeStatus::Eol => IncidentScope::Unknown,
            self::listsBranch($this->unaffected_branches ?? [], $versions) => IncidentScope::NotAffected,
            default => IncidentScope::Unknown,
        };
    }

    /**
     * The branches of the runtimes the vendor confirmed as affected (see scopeFor()): the runtime's branch, or its
     * version when the branch is not known.
     *
     * @param  Collection<int, Runtime>  $runtimes
     * @return list<string>
     */
    public function confirmedBranches(Collection $runtimes): array
    {
        return $runtimes
            ->filter(fn (Runtime $runtime): bool => $this->scopeFor($runtime) === IncidentScope::Confirmed)
            ->map(fn (Runtime $runtime): string => (string) ($runtime->branch ?? $runtime->version ?? $runtime->raw_version))
            ->unique()
            ->sort(SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * Derives the title, severity, dates and branches from the pre-notices that were not dismissed: the title of the
     * first one, the highest known severity, the release time of the latest one that gives one. `changed_at` moves only
     * when what the incident asks of the teams changes, its severity, branches or the CVE ids of it CISA KEV lists, so news that only adds to it, a merge
     * or a split of news at the same severity does not bring it back to the digest. An incident left with no such
     * pre-notice keeps its last title.
     *
     * The incident row is locked before its pre-notices are read, so a refresh running alongside a merge or split
     * reads what they committed and never writes back an older state.
     */
    public function refreshFromNotices(): void
    {
        DB::transaction(function (): void {
            $locked = self::whereKey($this->id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $this->setRawAttributes($locked->getAttributes(), true);

            $notices = $this->notices()->where('is_dismissed', false)->orderBy('published_at')->orderBy('id')->get();
            $first = $notices->first();
            $severity = self::highestSeverity($notices);
            $affected = $this->branches($notices, 'affected_branches');
            $unaffected = $this->branches($notices, 'unaffected_branches');

            $this->fill([
                'title' => $first?->title ?? $this->title,
                'severity' => $severity,
                'first_published_at' => $first?->published_at ?? $this->first_published_at,
                'planned_release_at' => $notices->whereNotNull('planned_release_at')->sortBy('published_at')->last()?->planned_release_at,
                'affected_branches' => $affected,
                'unaffected_branches' => $unaffected,
            ]);

            $knownExploited = $notices->flatMap(fn (Advisory $notice): array => KnownExploitation::cves($notice->known_exploited))->unique()->sort()->values()->all();
            $fingerprint = hash('sha256', (string) json_encode([$severity->value, $affected, $unaffected, ...($knownExploited === [] ? [] : [$knownExploited])]));

            if ($this->fingerprint !== $fingerprint) {
                $this->changed_at = $this->fingerprint === null ? ($this->changed_at ?? now()) : now();
                $this->fingerprint = $fingerprint;
            }

            $this->save();
        });
    }

    /**
     * The branches the vendor has a fix for: a pre-notice of the incident that was not dismissed names the version in
     * an "Update to …" sentence (see NoticeEntry::patchVersions()), and the catalog lists that version as a security
     * release of the branch. The highest such version of each branch, with the pre-notice that named it. Given the
     * incident's pre-notices, it reads them instead of loading them again; given the security releases (version =>
     * branch, e.g. read once for several incidents of the technology), it reads those too.
     *
     * @param  Collection<int, Advisory>|null  $notices
     * @param  Collection<string, string>|null  $securityReleases  Version => branch of the technology's security releases, at least those the pre-notices name.
     * @return array<string, array{version: string, notice: Advisory}>
     */
    public function confirmedPatches(?Collection $notices = null, ?Collection $securityReleases = null): array
    {
        $notices = ($notices ?? $this->notices()->get())
            ->filter(fn (Advisory $notice): bool => ! $notice->is_dismissed && $notice->patch_versions !== null)
            ->sortBy('id')
            ->values();
        $stated = $notices->flatMap(fn (Advisory $notice): array => $notice->patch_versions ?? [])->unique()->values();

        if ($stated->isEmpty()) {
            return [];
        }

        $securityReleases ??= self::securityReleases($this->technology, $stated->all());

        $patches = [];

        foreach ($notices as $notice) {
            foreach ($notice->patch_versions ?? [] as $version) {
                $branch = $securityReleases->get($version);

                if ($branch !== null && (! isset($patches[$branch]) || Version::isNewer($version, $patches[$branch]['version']))) {
                    $patches[$branch] = ['version' => $version, 'notice' => $notice];
                }
            }
        }

        return $patches;
    }

    /**
     * The security releases of the technology among the versions, version => branch.
     *
     * @param  list<string>  $versions
     * @return Collection<string, string>
     */
    public static function securityReleases(Technology $technology, array $versions): Collection
    {
        if ($versions === []) {
            return collect();
        }

        return RuntimeRelease::where('technology', $technology)
            ->where('is_security_release', true)
            ->whereIn('version', $versions)
            ->pluck('branch', 'version');
    }

    /**
     * Security releases of the branches that came out since the incident began, the latest of each branch: they may be
     * the fix, but no pre-notice says so yet.
     *
     * @param  list<string>  $branches
     * @return array<string, RuntimeRelease>
     */
    public function candidatePatches(array $branches): array
    {
        if ($branches === [] || $this->first_published_at === null) {
            return [];
        }

        return RuntimeRelease::where('technology', $this->technology)
            ->whereIn('branch', $branches)
            ->where('is_security_release', true)
            ->whereDate('released_at', '>=', $this->first_published_at->copy()->subDay()->toDateString())
            ->get()
            ->groupBy('branch')
            ->map(fn (Collection $releases): RuntimeRelease => $releases->sort(fn (RuntimeRelease $a, RuntimeRelease $b): int => version_compare($b->version, $a->version))->first())
            ->all();
    }

    /**
     * The highest known severity of the pre-notices, or unknown when none gives one.
     *
     * @param  Collection<int, Advisory>  $notices
     */
    public static function highestSeverity(Collection $notices): Severity
    {
        return $notices->pluck('severity')->sortByDesc(fn (Severity $severity): int => $severity->rank())->first() ?? Severity::Unknown;
    }

    /**
     * Whether `$branches` names the branch or version of a runtime: "8.3" names 8.3 and 8.3.35, "22" names 22 and
     * 22.20.0. A narrower entry never names a wider one: "22.1" does not name the branch 22.
     *
     * @param  list<string>  $branches
     * @param  list<string>  $versions  The runtime's branch and version.
     */
    public static function listsBranch(array $branches, array $versions): bool
    {
        return collect($branches)->contains(fn (string $branch): bool => collect($versions)->contains(fn (string $version): bool => $branch === $version || str_starts_with($version, "{$branch}.")));
    }

    /**
     * @param  Collection<int, Advisory>  $notices
     * @return list<string>
     */
    private function branches(Collection $notices, string $attribute): array
    {
        return $notices->flatMap(fn (Advisory $notice): array => $notice->{$attribute} ?? [])->unique()->sort(SORT_NATURAL)->values()->all();
    }
}

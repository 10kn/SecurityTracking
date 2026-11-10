<?php

namespace App\Models;

use App\Enums\IncidentScope;
use App\Enums\OperatingSystem;
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
#[Fillable(['technology', 'title', 'severity', 'first_published_at', 'last_published_at', 'planned_release_at', 'affected_branches', 'unaffected_branches', 'platform_scopes', 'merged_into_id', 'fingerprint', 'changed_at'])]
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
            'last_published_at' => 'datetime',
            'planned_release_at' => 'datetime',
            'affected_branches' => 'array',
            'unaffected_branches' => 'array',
            'platform_scopes' => 'array',
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
     * Incidents whose every pre-notice does not count as security news: the classifier dismissed it, or it has not read
     * it, e.g. news stored before `psm.notices.collect_days` limited what the classifier reads (see SyncNoticeFeed).
     *
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    #[Scope]
    protected function dismissed(Builder $query): Builder
    {
        return $query->whereHas('notices')->whereDoesntHave('notices', fn (Builder $query) => $query->where('is_dismissed', false));
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
     * affected is confirmed. A branch first released after the incident's latest pre-notice and its planned release is
     * ruled out too, end of life or not: it came out with the fix, e.g. PHP 8.5 for news about PHP 7.3 from 2019.
     *
     * A runtime whose deployment runs an operating system every pre-notice excludes is ruled out, end of life or not
     * (see excludedBy()). Otherwise, once a pre-notice is limited to some systems, only the pre-notices that apply to
     * the runtime's system confirm or rule out its branch (see platform_scopes): news about PHP 8.4 on Windows does not
     * confirm 8.4 on Linux. A runtime whose system is not known reads every pre-notice, as before.
     */
    public function scopeFor(Runtime $runtime): IncidentScope
    {
        $scope = $this->platformScopeOf($runtime->deployment?->os);

        if ($scope === false) {
            return IncidentScope::NotAffected;
        }

        $versions = array_values(array_filter([$runtime->branch, $runtime->version ?? $runtime->raw_version], filled(...)));

        return match (true) {
            self::listsBranch($scope['affected'] ?? $this->affected_branches ?? [], $versions) => IncidentScope::Confirmed,
            $this->predates($runtime->branch) => IncidentScope::NotAffected,
            $runtime->status === RuntimeStatus::Eol => IncidentScope::Unknown,
            self::listsBranch($scope['unaffected'] ?? $this->unaffected_branches ?? [], $versions) => IncidentScope::NotAffected,
            default => IncidentScope::Unknown,
        };
    }

    /**
     * The operating systems the incident is limited to, when every pre-notice of it that was not dismissed excludes the
     * system the runtime's deployment runs, e.g. [Windows] for a service on Linux; null when the runtime may be
     * affected: no pre-notice is limited, one that is not limited or that names the system applies, or the system is
     * not known.
     *
     * @return list<OperatingSystem>|null
     */
    public function excludedBy(Runtime $runtime): ?array
    {
        return $this->excludes($runtime->deployment?->os) ? $this->platforms() : null;
    }

    /**
     * Whether every pre-notice of the incident that was not dismissed excludes the operating system. An unknown one is
     * never excluded.
     */
    public function excludes(?OperatingSystem $system): bool
    {
        return $this->platformScopeOf($system) === false;
    }

    /**
     * The operating systems some pre-notice of the incident that was not dismissed applies to, once one is limited to
     * some (see refreshFromNotices()); empty when none is.
     *
     * @return list<OperatingSystem>
     */
    public function platforms(): array
    {
        return collect(array_keys($this->platform_scopes ?? []))
            ->map(fn (int|string $value): ?OperatingSystem => OperatingSystem::tryFrom((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * What the pre-notices that apply to the operating system say of the branches; false when none applies, null when
     * the incident reads every pre-notice for it: none is limited, or the system is not known.
     *
     * @return array{affected: list<string>, unaffected: list<string>}|false|null
     */
    private function platformScopeOf(?OperatingSystem $system): array|false|null
    {
        if ($this->platform_scopes === null || $system === null) {
            return null;
        }

        return $this->platform_scopes[$system->value] ?? false;
    }

    /**
     * Whether the branch was first released after the day of the incident's latest pre-notice and of its planned
     * release, by the technology's version catalog. Without those dates, or a branch the catalog does not date, nothing
     * says so.
     */
    public function predates(?string $branch): bool
    {
        $told = collect([$this->last_published_at ?? $this->first_published_at, $this->planned_release_at])->filter()->max();

        if ($branch === null || $told === null) {
            return false;
        }

        $releasedAt = RuntimeBranch::releaseDates($this->technology)->get($branch);

        return $releasedAt !== null && $releasedAt->toDateString() > $told->toDateString();
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
     * first one, the highest known severity, the release time of the latest one that gives one, and what they say per
     * operating system once one of them is limited to some (see platformScopesOf()). `changed_at` moves only
     * when what the incident asks of the teams changes, its severity, branches, operating systems or the CVE ids of it CISA KEV lists, so news that only adds to it, a merge
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
            $platformScopes = $this->platformScopesOf($notices);

            $this->fill([
                'title' => $first?->title ?? $this->title,
                'severity' => $severity,
                'first_published_at' => $first?->published_at ?? $this->first_published_at,
                'last_published_at' => $notices->max('published_at') ?? $this->last_published_at,
                'planned_release_at' => $notices->whereNotNull('planned_release_at')->sortBy('published_at')->last()?->planned_release_at,
                'affected_branches' => $affected,
                'unaffected_branches' => $unaffected,
                'platform_scopes' => $platformScopes,
            ]);

            $knownExploited = $notices->flatMap(fn (Advisory $notice): array => KnownExploitation::cves($notice->known_exploited))->unique()->sort()->values()->all();
            $fingerprint = hash('sha256', (string) json_encode([$severity->value, $affected, $unaffected, ...($knownExploited === [] ? [] : [$knownExploited]), ...($platformScopes === null ? [] : [['platforms' => $platformScopes]])]));

            if ($this->fingerprint !== $fingerprint) {
                $this->changed_at = $this->fingerprint === null ? ($this->changed_at ?? now()) : now();
                $this->fingerprint = $fingerprint;
            }

            $this->save();
        });
    }

    /**
     * The branches the vendor has a fix for: the classifier read in a pre-notice of the incident that was not dismissed
     * that the version is the one to update to (`patch_versions`), and the catalog lists that version as a security
     * release of the branch. A pre-notice limited to some operating systems confirms no fix: a Windows build such as
     * 8.4.27-1 does not fix 8.4 on Linux, and the catalog's 8.4.27 is not that build. The highest such version of each branch, with the pre-notice that named it. Given the
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
            ->filter(fn (Advisory $notice): bool => ! $notice->is_dismissed && $notice->patch_versions !== null && $notice->platformLimit() === [])
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
     * What the pre-notices say per operating system once one of them is limited to some: for each system some
     * pre-notice applies to, the branches named affected and not affected by those pre-notices only. A pre-notice that
     * states no limit applies to every system. A system left out is one every pre-notice excludes. Null when no
     * pre-notice is limited, so the incident reads them all for every runtime.
     *
     * @param  Collection<int, Advisory>  $notices
     * @return array<string, array{affected: list<string>, unaffected: list<string>}>|null
     */
    private function platformScopesOf(Collection $notices): ?array
    {
        if (! $notices->contains(fn (Advisory $notice): bool => $notice->platformLimit() !== [])) {
            return null;
        }

        $scopes = [];

        foreach (OperatingSystem::cases() as $system) {
            $applying = $notices->filter(fn (Advisory $notice): bool => $notice->platformLimit() === [] || in_array($system, $notice->platformLimit(), true));

            if ($applying->isNotEmpty()) {
                $scopes[$system->value] = ['affected' => $this->branches($applying, 'affected_branches'), 'unaffected' => $this->branches($applying, 'unaffected_branches')];
            }
        }

        return $scopes;
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

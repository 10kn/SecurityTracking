<?php

namespace App\Actions;

use App\Enums\AdvisoryKind;
use App\Models\Advisory;
use App\Models\IncidentTeamNotice;
use App\Models\SourceHeartbeat;
use App\Services\Catalog\CatalogHttp;
use App\Services\Kev\KnownExploitation;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finds the CVE ids of the GitHub advisory ids (GHSA) pre-notices name, so a pre-notice that names only a GHSA id is
 * matched against CISA KEV, which lists CVE ids (see MatchKnownExploited). A GHSA id and its CVE ids are the same
 * vulnerability, so no other CVE is ever added. The package advisories already stored are read first (Packagist, OSV
 * and RubySec list both ids), then the GitHub advisory database at `psm.kev.ghsa_api_url`.
 *
 * Each GHSA id of every pre-notice is looked up once, whether it may still alert or not (CISA may add its CVE years
 * later), and again on each run while it has no CVE yet (a CVE is often assigned after the vendor's news) for
 * pre-notices that may still alert: published within `psm.notices.relevant_days`, or of an incident a team was told
 * about. Every lookup is recorded on the `kev:ghsa` heartbeat, so a database that keeps
 * failing alerts the admin.
 */
class ResolveNoticeCves
{
    public const string SOURCE = 'kev:ghsa';

    /**
     * @return int The number of pre-notices whose CVE ids changed.
     */
    public function handle(): int
    {
        $heartbeat = SourceHeartbeat::updateOrCreate(['source' => self::SOURCE], [
            'name' => __('Mã CVE của tin GitHub (GHSA)'),
            'threshold_minutes' => config('psm.kev.stale_after_minutes'),
        ]);

        $changed = 0;
        $failure = null;

        foreach ($this->pending() as ['notice' => $notice, 'ghsa_ids' => $ghsaIds]) {
            $resolved = $notice->resolved_cve_ids ?? [];
            $before = KnownExploitation::cveIdsOf($notice);

            foreach ($ghsaIds as $ghsaId) {
                try {
                    $resolved[$ghsaId] = $this->lookUp($ghsaId);
                } catch (Throwable $exception) {
                    report($exception);
                    $failure ??= $exception;
                }
            }

            $notice->update(['resolved_cve_ids' => $resolved, 'cves_resolved_at' => now()]);
            $changed += (int) (KnownExploitation::cveIdsOf($notice) !== $before);
        }

        $heartbeat->update($failure === null
            ? ['last_success_at' => now(), 'last_error' => null]
            : ['last_failure_at' => now(), 'last_error' => Str::limit(class_basename($failure).': '.$failure->getMessage(), 500)]);

        return $changed;
    }

    /**
     * Pre-notices with a GHSA id never looked up, or, among those that may still alert, one without a CVE yet.
     *
     * @return Collection<int, array{notice: Advisory, ghsa_ids: non-empty-list<string>}>
     */
    private function pending(): Collection
    {
        $toldIncidentIds = Advisory::whereKey(IncidentTeamNotice::toldNoticeIds())->whereNotNull('incident_id')->distinct()->pluck('incident_id')->all();
        $since = now()->subDays(config('psm.notices.relevant_days'));

        return Advisory::where('kind', AdvisoryKind::PreNotice)
            ->whereJsonLength('aliases', '>', 0)
            ->orderBy('id')
            ->get()
            ->map(fn (Advisory $notice): array => ['notice' => $notice, 'ghsa_ids' => $this->unresolvedGhsaIds(
                $notice,
                ! $notice->is_dismissed && (($notice->published_at !== null && $notice->published_at->gte($since)) || in_array($notice->incident_id, $toldIncidentIds, true)),
            )])
            ->filter(fn (array $item): bool => $item['ghsa_ids'] !== [])
            ->values();
    }

    /**
     * The GHSA ids of the pre-notice never looked up, and, when it may still alert, those without a CVE yet.
     *
     * @return list<string>
     */
    private function unresolvedGhsaIds(Advisory $notice, bool $mayAlert): array
    {
        return collect($notice->aliases ?? [])
            ->filter(fn (mixed $alias): bool => is_string($alias) && str_starts_with($alias, 'GHSA-'))
            ->reject(fn (string $ghsaId): bool => isset($notice->resolved_cve_ids[$ghsaId]) && (filled($notice->resolved_cve_ids[$ghsaId]['cves'] ?? null) || ! $mayAlert))
            ->values()
            ->all();
    }

    /**
     * The CVE ids of a GHSA id: those of the package advisories stored under it, else those GitHub gives. None when
     * GitHub has no advisory with that id yet.
     *
     * @return array{cves: list<string>, source: string}
     *
     * @throws Throwable
     */
    private function lookUp(string $ghsaId): array
    {
        $local = Advisory::where('kind', AdvisoryKind::Vulnerability)
            ->where(fn ($query) => $query->where('external_id', $ghsaId)->orWhereJsonContains('aliases', $ghsaId))
            ->get()
            ->flatMap(fn (Advisory $advisory): array => KnownExploitation::cveIdsOf($advisory))
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($local !== []) {
            return ['cves' => $local, 'source' => 'local'];
        }

        try {
            $advisory = CatalogHttp::client(config('psm.kev.http_timeout_seconds'))
                ->accept('application/vnd.github+json')
                ->retry(2, 500, fn (Throwable $exception): bool => ! $exception instanceof RequestException || $exception->response->status() !== 404)
                ->get(rtrim((string) config('psm.kev.ghsa_api_url'), '/').'/'.rawurlencode($ghsaId))
                ->json();
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return ['cves' => [], 'source' => 'github'];
            }

            throw $exception;
        }

        $identifiers = is_array($advisory['identifiers'] ?? null) ? $advisory['identifiers'] : [];
        $ids = [$advisory['cve_id'] ?? null, ...array_column(array_filter($identifiers, fn (mixed $identifier): bool => is_array($identifier) && ($identifier['type'] ?? null) === 'CVE'), 'value')];

        $cves = collect($ids)
            ->filter(fn (mixed $id): bool => is_string($id) && preg_match('/^CVE-\d{4}-\d{4,}$/i', $id) === 1)
            ->map(fn (string $id): string => strtoupper($id))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return ['cves' => $cves, 'source' => 'github'];
    }
}

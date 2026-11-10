<?php

namespace App\Actions;

use App\Enums\AdvisoryKind;
use App\Enums\IncidentBasis;
use App\Enums\IncidentSuggestionReason;
use App\Enums\IncidentSuggestionStatus;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Services\Notices\NoticeUrl;
use App\Support\IncidentLock;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts each pre-notice in an incident. A new pre-notice joins an existing incident only on firm evidence: it shares a
 * CVE or GHSA id with a pre-notice of the incident, or one of them links to the other. Evidence pointing at several
 * incidents is not acted on: the pre-notice starts its own incident and each match becomes a suggestion. Two existing
 * incidents are never merged and a pre-notice never moves on its own: evidence that appears when the vendor edits it
 * only suggests, and only an admin merges (see MergeIncidents, SplitNotice). Pre-notices an admin placed are left
 * alone.
 *
 * Weaker hints only suggest: a pre-notice published around the release another one planned
 * (`psm.incidents.release_window_hours`), and the classifier (see suggestFromClassifier()). A rejected suggestion
 * stays rejected; another reason is a new suggestion.
 *
 * Incidents of a technology are assigned one pre-notice at a time, under a database lock, so two polls cannot start
 * two incidents for the same news.
 */
class AssignNoticeToIncident
{
    /**
     * Pre-notices stored before incidents existed, or by other code, get theirs here, oldest first.
     */
    public function assignUnassigned(): void
    {
        Advisory::where('kind', AdvisoryKind::PreNotice)
            ->whereNotNull('technology')
            ->whereNull('incident_id')
            ->orderBy('published_at')
            ->orderBy('id')
            ->get()
            ->each(fn (Advisory $notice) => $this->handle($notice));
    }

    public function handle(Advisory $notice): void
    {
        if ($notice->kind !== AdvisoryKind::PreNotice || $notice->technology === null) {
            return;
        }

        DB::transaction(function () use ($notice): void {
            $this->lock($notice->technology);
            $notice->refresh();

            $others = $this->candidates($notice);

            $matches = $this->firmEvidence($notice, $others)->groupBy('incident_id');

            if ($notice->incident_id === null) {
                $this->assign($notice, $matches);
            } elseif ($notice->incident_basis !== IncidentBasis::Manual) {
                $matches->except([$notice->incident_id])->each(fn (Collection $evidence, int $incidentId) => $this->suggest($notice, $incidentId, $evidence->first()['reason'], $evidence->first()));
            }

            $this->suggestFromReleaseWindows($notice, $others);
        });
    }

    /**
     * The classifier thinks the pre-notice is about another incident of the technology: a suggestion for an admin.
     */
    public function suggestFromClassifier(Advisory $notice, ?int $incidentId): void
    {
        if ($incidentId === null) {
            return;
        }

        $notice->refresh();

        if ($notice->incident_id === null || $notice->incident_id === null || $notice->incident_id === $incidentId || $notice->incident_basis === IncidentBasis::Manual) {
            return;
        }

        if (Incident::current()->whereKey($incidentId)->where('technology', $notice->technology)->exists()) {
            $this->suggest($notice, $incidentId, IncidentSuggestionReason::Classifier, null);
        }
    }

    /**
     * The most pre-notices of an incident whose facts the classifier is given, the latest ones: a long incident would
     * otherwise crowd out the others (see ClaudeNoticeClassifier::INCIDENT_FACTS_LENGTH for the size of the whole list).
     */
    public const int CLASSIFIER_NOTICES_PER_INCIDENT = 5;

    /**
     * Recent incidents of the technology the classifier may relate an entry to: those with a pre-notice published
     * within `psm.notices.relevant_days`, so an older incident that just got a follow-up is offered too, the latest
     * publication first. Each comes with the facts read from its latest pre-notices that were not dismissed (at most
     * CLASSIFIER_NOTICES_PER_INCIDENT, oldest first) and how many such pre-notices it has. Only what tells two pieces
     * of news apart, one pre-notice at a time: its title, dates, CVE / GHSA ids, branches and patch versions. Not its
     * severity, summary or words, which the classifier could copy into what it reads from the entry. Two queries,
     * however many incidents.
     *
     * @return list<array{id: int, title: string, published_at: ?CarbonInterface, notice_count: int, notices: list<array{title: string, published_at: ?CarbonInterface, aliases: list<string>, planned_release_at: ?CarbonInterface, affected_branches: list<string>, unaffected_branches: list<string>, patch_versions: list<string>}>}>
     */
    public function recentIncidents(Technology $technology, ?int $exceptId): array
    {
        $listed = fn ($query) => $query->where('is_dismissed', false);

        return Incident::current()
            ->where('technology', $technology)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereRaw('coalesce(last_published_at, first_published_at) >= ?', [now()->subDays(config('psm.notices.relevant_days'))])
            ->orderByRaw('coalesce(last_published_at, first_published_at) desc')
            ->limit(20)
            ->withCount(['notices' => $listed])
            ->with(['notices' => fn ($query) => $listed($query)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(self::CLASSIFIER_NOTICES_PER_INCIDENT)
                ->select(['id', 'incident_id', 'title', 'published_at', 'aliases', 'planned_release_at', 'affected_branches', 'unaffected_branches', 'patch_versions'])])
            ->get()
            ->map(fn (Incident $incident): array => [
                'id' => $incident->id,
                'title' => $incident->title,
                'published_at' => $incident->first_published_at,
                'notice_count' => $incident->notices_count,
                'notices' => $incident->notices->reverse()->map(fn (Advisory $notice): array => [
                    'title' => $notice->title,
                    'published_at' => $notice->published_at,
                    'aliases' => $notice->aliases ?? [],
                    'planned_release_at' => $notice->planned_release_at,
                    'affected_branches' => $notice->affected_branches ?? [],
                    'unaffected_branches' => $notice->unaffected_branches ?? [],
                    'patch_versions' => $notice->patch_versions ?? [],
                ])->values()->all(),
            ])
            ->all();
    }

    /**
     * Serializes the assignment, merges and splits of the incidents of a technology until the transaction ends (see
     * IncidentLock).
     */
    public function lock(Technology $technology): void
    {
        IncidentLock::take([$technology]);
    }

    /**
     * The pre-notices of the technology that may share an id with the pre-notice, link to it or from it, or have a
     * release around it, narrowed by the database so a long history is not read for every pre-notice. Each candidate is
     * checked again in PHP.
     *
     * @return Collection<int, Advisory>
     */
    private function candidates(Advisory $notice): Collection
    {
        $ids = $notice->aliases ?? [];
        $addresses = $this->addresses($notice)->all();
        $links = collect($notice->links ?? [])->flatMap(fn (string $link): array => NoticeUrl::storedForms($link))->unique()->values()->all();
        $before = config('psm.incidents.release_window_hours.before');
        $after = config('psm.incidents.release_window_hours.after');

        return Advisory::where('source', $notice->source)
            ->where('kind', AdvisoryKind::PreNotice)
            ->where('technology', $notice->technology)
            ->whereKeyNot($notice->id)
            ->whereNotNull('incident_id')
            ->where(fn ($query) => $query
                ->when($ids !== [], fn ($query) => $query->orWhereRaw('jsonb_exists_any(aliases, ?::text[])', [$this->textArray($ids)]))
                ->when($addresses !== [], fn ($query) => $query->orWhereRaw('jsonb_exists_any(links, ?::text[])', [$this->textArray($addresses)]))
                ->when($links !== [], fn ($query) => $query->orWhereIn(DB::raw('lower(url)'), $links)->orWhereIn(DB::raw('lower(external_id)'), $links))
                ->when($notice->published_at !== null, fn ($query) => $query->orWhereBetween('planned_release_at', [$notice->published_at->copy()->subHours($after), $notice->published_at->copy()->addHours($before)]))
                ->when($notice->planned_release_at !== null, fn ($query) => $query->orWhereBetween('published_at', [$notice->planned_release_at->copy()->subHours($before), $notice->planned_release_at->copy()->addHours($after)]))
                ->orWhereRaw('false'))
            ->get(['id', 'incident_id', 'incident_basis', 'aliases', 'links', 'url', 'external_id', 'is_dismissed', 'published_at', 'planned_release_at']);
    }

    /**
     * A PostgreSQL text[] literal, e.g. {"CVE-2026-1001","GHSA-w8hw-w9fx-j79w"}.
     *
     * @param  list<string>  $values
     */
    private function textArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (string $value): string => '"'.addcslashes($value, '"\\').'"', $values)).'}';
    }

    /**
     * @param  Collection<int, Collection<int, array{incident_id: int, advisory_id: int, reason: IncidentSuggestionReason, value: string}>>  $matches
     */
    private function assign(Advisory $notice, Collection $matches): void
    {
        if ($matches->count() === 1) {
            $evidence = $matches->first();

            $notice->update([
                'incident_id' => $matches->keys()->first(),
                'incident_basis' => $evidence->first()['reason'] === IncidentSuggestionReason::SharedId ? IncidentBasis::SharedId : IncidentBasis::DirectLink,
                'incident_evidence' => $evidence->map(fn (array $item): array => ['type' => $item['reason']->value, 'value' => $item['value'], 'advisory_id' => $item['advisory_id']])->values()->all(),
            ]);

            return;
        }

        $incident = Incident::create([
            'technology' => $notice->technology,
            'title' => $notice->title,
            'severity' => $notice->severity,
            'first_published_at' => $notice->published_at,
            'changed_at' => $notice->updated_at ?? now(),
        ]);

        $notice->update(['incident_id' => $incident->id, 'incident_basis' => IncidentBasis::Own, 'incident_evidence' => null]);

        $matches->each(fn (Collection $evidence, int $incidentId) => $this->suggest($notice, $incidentId, $evidence->first()['reason'], $evidence->first()));
    }

    /**
     * Shared CVE or GHSA ids, and links from one pre-notice to the address of the other.
     *
     * @param  Collection<int, Advisory>  $others
     * @return Collection<int, array{incident_id: int, advisory_id: int, reason: IncidentSuggestionReason, value: string}>
     */
    private function firmEvidence(Advisory $notice, Collection $others): Collection
    {
        $ids = collect($notice->aliases ?? []);
        $links = collect($notice->links ?? []);
        $addresses = $this->addresses($notice);

        return $others->flatMap(function (Advisory $other) use ($ids, $links, $addresses): array {
            $sharedId = $ids->intersect($other->aliases ?? [])->first();
            $link = $links->intersect($this->addresses($other))->first() ?? collect($other->links ?? [])->intersect($addresses)->first();

            return array_values(array_filter([
                $sharedId === null ? null : ['incident_id' => $other->incident_id, 'advisory_id' => $other->id, 'reason' => IncidentSuggestionReason::SharedId, 'value' => $sharedId],
                $link === null ? null : ['incident_id' => $other->incident_id, 'advisory_id' => $other->id, 'reason' => IncidentSuggestionReason::DirectLink, 'value' => $link],
            ]));
        });
    }

    /**
     * @return Collection<int, string>
     */
    private function addresses(Advisory $notice): Collection
    {
        return collect([NoticeUrl::normalize($notice->url), NoticeUrl::normalize($notice->external_id)])->filter()->unique()->values();
    }

    /**
     * A pre-notice published within `psm.incidents.release_window_hours` of the release another incident planned may
     * be its release: suggested both when the pre-notice is new and when the other learns its planned release. Only
     * fresh pre-notices that were not dismissed or placed by an admin take part.
     *
     * @param  Collection<int, Advisory>  $others
     */
    private function suggestFromReleaseWindows(Advisory $notice, Collection $others): void
    {
        $isCandidate = fn (Advisory $advisory): bool => ! $advisory->is_dismissed
            && $advisory->incident_basis !== IncidentBasis::Manual
            && $advisory->published_at !== null
            && ! $advisory->published_at->isBefore(now()->subDays(config('psm.notices.relevant_days')));

        if (! $isCandidate($notice)) {
            return;
        }

        $candidates = $others->filter($isCandidate)->where('incident_id', '!=', $notice->incident_id);

        $candidates
            ->filter(fn (Advisory $other): bool => $this->isAroundRelease($notice->published_at, $other->planned_release_at))
            ->each(fn (Advisory $other) => $this->suggest($notice, $other->incident_id, IncidentSuggestionReason::ReleaseWindow, ['advisory_id' => $other->id, 'value' => $other->planned_release_at->toIso8601String()]));

        $candidates
            ->filter(fn (Advisory $other): bool => $this->isAroundRelease($other->published_at, $notice->planned_release_at))
            ->each(fn (Advisory $other) => $this->suggest($other, $notice->incident_id, IncidentSuggestionReason::ReleaseWindow, ['advisory_id' => $notice->id, 'value' => $notice->planned_release_at->toIso8601String()]));
    }

    private function isAroundRelease(CarbonInterface $publishedAt, ?CarbonInterface $plannedReleaseAt): bool
    {
        return $plannedReleaseAt !== null && $publishedAt->between(
            $plannedReleaseAt->copy()->subHours(config('psm.incidents.release_window_hours.before')),
            $plannedReleaseAt->copy()->addHours(config('psm.incidents.release_window_hours.after')),
        );
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    private function suggest(Advisory $notice, int $incidentId, IncidentSuggestionReason $reason, ?array $evidence): void
    {
        IncidentSuggestion::firstOrCreate(
            ['advisory_id' => $notice->id, 'incident_id' => $incidentId, 'reason' => $reason],
            ['evidence' => $evidence === null ? null : ['advisory_id' => $evidence['advisory_id'] ?? null, 'value' => $evidence['value'] ?? null], 'status' => IncidentSuggestionStatus::Pending],
        );
    }
}

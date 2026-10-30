<?php

namespace App\Services;

use App\Enums\FindingState;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The findings of an incident, open or resolved, linked to it by explicit keys only: a finding about one of its
 * pre-notices (`advisory_id`), or a "thiếu bản vá" finding listing a fix the incident confirmed (`related_patches`, see
 * RuntimeFindings). A finding is never matched by technology or version.
 *
 * `related_patches` is written when the finding is evaluated, so it may name an incident that was merged or a
 * pre-notice that was split off since. Each entry counts for the incident its pre-notice is in now, even none; only
 * an entry whose pre-notice is gone counts for the incident its incident was merged into. Nothing is written back.
 */
class IncidentFindings
{
    /**
     * Open findings not marked as read first, then those marked as read, then resolved ones; the most recently seen
     * first within each. Each comes with the fixed version of this incident its `related_patches` lists
     * (`incident_fix`), null for a finding about one of its pre-notices.
     *
     * @param  EloquentCollection<int, Advisory>  $notices  The incident's pre-notices.
     * @return Collection<int, array{finding: Finding, incident_fix: string|null}>
     */
    public function of(Incident $incident, EloquentCollection $notices): Collection
    {
        $noticeIds = $notices->modelKeys();
        $incidentIds = [$incident->id, ...Incident::where('merged_into_id', $incident->id)->pluck('id')->all()];

        $candidates = Finding::query()
            ->with(['deployment.service', 'team'])
            ->where(function (Builder $query) use ($noticeIds, $incidentIds): void {
                $query->whereIn('advisory_id', $noticeIds);

                foreach ($incidentIds as $incidentId) {
                    $query->orWhereRaw("context->'related_patches' @> ?::jsonb", [json_encode([['incident_id' => $incidentId]])]);
                }

                foreach ($noticeIds as $noticeId) {
                    $query->orWhereRaw("context->'related_patches' @> ?::jsonb", [json_encode([['notice_id' => $noticeId]])]);
                }
            })
            ->get();

        $entries = $candidates->flatMap(fn (Finding $finding): array => $this->relatedPatchesOf($finding));
        $incidentOfNotice = Advisory::whereKey($entries->pluck('notice_id')->filter()->unique()->values()->all())->get(['id', 'incident_id'])->mapWithKeys(fn (Advisory $notice): array => [$notice->id => $notice->incident_id]);
        $canonicalOf = $this->canonicalIncidents($entries->pluck('incident_id')->filter()->unique()->values()->all());
        $isOfIncident = fn (array $entry): bool => match (true) {
            $entry['notice_id'] !== null && $incidentOfNotice->has($entry['notice_id']) => $incidentOfNotice->get($entry['notice_id']) === $incident->id,
            $entry['incident_id'] !== null => ($canonicalOf->get($entry['incident_id']) ?? $entry['incident_id']) === $incident->id,
            default => false,
        };

        return $candidates
            ->map(fn (Finding $finding): array => [
                'finding' => $finding,
                'entries' => collect($this->relatedPatchesOf($finding))->filter($isOfIncident),
            ])
            ->filter(fn (array $item): bool => in_array($item['finding']->advisory_id, $noticeIds, true) || $item['entries']->isNotEmpty())
            ->sortBy([
                fn (array $a, array $b): int => $this->rank($a['finding']) <=> $this->rank($b['finding']),
                fn (array $a, array $b): int => ($b['finding']->last_seen_at?->getTimestamp() ?? 0) <=> ($a['finding']->last_seen_at?->getTimestamp() ?? 0),
                fn (array $a, array $b): int => $a['finding']->id <=> $b['finding']->id,
            ])
            ->map(fn (array $item): array => ['finding' => $item['finding'], 'incident_fix' => $item['entries']->pluck('fixed_version')->filter()->sort(SORT_NATURAL)->last()])
            ->values();
    }

    /**
     * @return list<array{incident_id: int|null, notice_id: int|null, fixed_version: string|null}>
     */
    private function relatedPatchesOf(Finding $finding): array
    {
        $entries = $finding->context['related_patches'] ?? [];

        return is_array($entries) ? array_values(array_map(fn (mixed $entry): array => [
            'incident_id' => is_array($entry) && is_int($entry['incident_id'] ?? null) ? $entry['incident_id'] : null,
            'notice_id' => is_array($entry) && is_int($entry['notice_id'] ?? null) ? $entry['notice_id'] : null,
            'fixed_version' => is_array($entry) && is_string($entry['fixed_version'] ?? null) ? $entry['fixed_version'] : null,
        ], $entries)) : [];
    }

    /**
     * The incident each one was merged into, followed to the end, by id; a current incident is left out.
     *
     * @param  list<int>  $incidentIds
     * @return Collection<int, int>
     */
    private function canonicalIncidents(array $incidentIds): Collection
    {
        $mergedInto = collect();
        $pending = $incidentIds;

        for ($hops = 0; $pending !== [] && $hops < 10; $hops++) {
            $next = Incident::whereKey($pending)->whereNotNull('merged_into_id')->pluck('merged_into_id', 'id');
            $mergedInto = $mergedInto->union($next);
            $pending = $next->values()->diff($mergedInto->keys())->unique()->values()->all();
        }

        return collect($incidentIds)->filter(fn (int $id): bool => $mergedInto->has($id))->mapWithKeys(function (int $id) use ($mergedInto): array {
            $canonical = $id;

            for ($hops = 0; $mergedInto->has($canonical) && $hops < 10; $hops++) {
                $canonical = $mergedInto->get($canonical);
            }

            return [$id => $canonical];
        });
    }

    private function rank(Finding $finding): int
    {
        return match (true) {
            $finding->state === FindingState::Open && ! $finding->isAcknowledged() => 0,
            $finding->state === FindingState::Open => 1,
            default => 2,
        };
    }
}

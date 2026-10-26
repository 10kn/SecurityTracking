<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IncidentImpact;
use App\Enums\IncidentScope;
use App\Enums\IncidentTeamEventType;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Http\Controllers\Controller;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamEvent;
use App\Models\IncidentTeamNotice;
use App\Models\Team;
use App\Services\IncidentOverview;
use App\Support\TableQuery;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The incidents vendor pre-notices were grouped into, where an admin reviews their pre-notices and suggestions, merges
 * and splits them, marks them as read for each team with its impact ("Đã đọc": Có / Không / Chưa rõ) and sends urgent
 * updates. The link in the daily digest opens the list filtered on one team and on what needs an admin.
 *
 * @phpstan-import-type IncidentState from IncidentOverview
 * @phpstan-import-type TeamState from IncidentOverview
 */
class IncidentController extends Controller
{
    public function __construct(private IncidentOverview $overview) {}

    /**
     * Current incidents, newest first, filtered by technology, by a team told about them, and on those needing an
     * admin (see IncidentOverview::needsAttention()), for the filtered team only when there is one. The page is read
     * from the database first; only the attention filter reads the incidents the database cannot rule out, all at
     * once (see IncidentOverview::states()).
     */
    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest($request, ['first_published_at', 'severity'], '-first_published_at');
        $technology = Technology::tryFrom($request->string('technology')->toString());
        $teamId = $request->integer('team') ?: null;
        $isAttention = $request->boolean('attention');

        $query = $table->search(Incident::current(), ['title'])
            ->when($technology !== null, fn (Builder $query) => $query->where('technology', $technology))
            ->when($teamId !== null, fn (Builder $query) => $query->whereIn('id', Advisory::whereKey(IncidentTeamNotice::noticeIdsSentTo($teamId))->whereNotNull('incident_id')->select('incident_id')))
            ->when(
                $table->sort === 'severity',
                fn (Builder $query) => $query->orderByRaw($this->severityOrder().' '.($table->descending ? 'asc' : 'desc')),
                fn (Builder $query) => $query->orderBy('first_published_at', $table->direction()),
            )
            ->orderByDesc('id');

        if ($isAttention) {
            $candidates = $this->overview->mayNeedAttention($query)->get();
            $states = $this->overview->states($candidates)->filter(fn (array $state): bool => $this->overview->needsAttention($state, $teamId));
            $matching = $candidates->filter(fn (Incident $incident): bool => $states->has($incident->id))->values();
            $page = LengthAwarePaginator::resolveCurrentPage();
            $incidents = new LengthAwarePaginator($matching->forPage($page, $table->perPage)->values(), $matching->count(), $table->perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);
        } else {
            $incidents = $query->paginate($table->perPage)->withQueryString();
            $states = $this->overview->states($incidents->getCollection());
        }

        $incidents->setCollection($incidents->getCollection()->map(fn (Incident $incident): array => $this->summary($states->get($incident->id))));

        return Inertia::render('incidents/index', [
            'incidents' => JsonResource::collection($incidents),
            'table' => [...$table->toArray(), 'technology' => $technology?->value, 'team' => $teamId, 'attention' => $isAttention ? 1 : null],
            'technologies' => array_map(fn (Technology $technology): array => ['value' => $technology->value, 'label' => $technology->getLabel()], Technology::cases()),
            'teams' => Team::orderBy('name')->get()->map(fn (Team $team): array => ['value' => $team->id, 'label' => $team->name])->all(),
        ]);
    }

    /**
     * An incident with its pre-notices, the pending suggestions about it, each team told about it or running its
     * technology, and what the merge and urgent update forms need. A merged incident opens the one it went into.
     */
    public function show(Request $request, Incident $incident): Response|RedirectResponse
    {
        if ($incident->merged_into_id !== null) {
            $canonical = $this->overview->canonical($incident);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Sự kiện #:from đã được gộp vào sự kiện này.', ['from' => $incident->id])]);

            return redirect()->route('admin.incidents.show', [$canonical, ...$request->only('team')]);
        }

        $state = $this->overview->state($incident, withHistory: true);
        $notices = $state['notices'];
        $teams = $state['teams'];
        $teamId = $request->integer('team') ?: null;

        return Inertia::render('incidents/show', [
            'incident' => [
                ...$this->summary($state),
                'planned_release' => $this->time($incident->planned_release_at),
                'affected_branches' => $incident->affected_branches ?? [],
                'unaffected_branches' => $incident->unaffected_branches ?? [],
                'is_exploited' => $notices->contains(fn (Advisory $notice): bool => ! $notice->is_dismissed && $notice->is_exploited === true),
                'merged_from' => Incident::where('merged_into_id', $incident->id)->orderBy('id')->get()->map(fn (Incident $merged): array => ['id' => $merged->id, 'title' => $merged->title])->all(),
            ],
            'notices' => $notices->map(fn (Advisory $notice): array => [
                'id' => $notice->id,
                'title' => $notice->title,
                'url' => $notice->url,
                'severity' => $this->severity($notice->severity),
                'published' => $this->time($notice->published_at),
                'affected_branches' => $notice->affected_branches ?? [],
                'unaffected_branches' => $notice->unaffected_branches ?? [],
                'patch_versions' => $notice->patch_versions ?? [],
                'is_exploited' => $notice->is_exploited === true,
                'exploited_excerpt' => $notice->exploited_basis['excerpt'] ?? null,
                'is_dismissed' => $notice->is_dismissed,
                'basis' => $notice->incident_basis?->getLabel(),
            ])->all(),
            'suggestions' => $this->suggestions($incident),
            'teams' => $teams->map(fn (array $team): array => $this->teamRow($incident, $team))->all(),
            'focusTeam' => $teamId,
            'mergeTargets' => Incident::current()
                ->where('technology', $incident->technology)
                ->whereKeyNot($incident->id)
                ->orderByDesc('first_published_at')
                ->limit(100)
                ->get()
                ->map(fn (Incident $target): array => ['value' => $target->id, 'label' => "#{$target->id} · {$target->title}"])
                ->all(),
            'update' => [
                'request_id' => (string) Str::uuid(),
                'recipients' => $teams->where('is_told', true)->map(fn (array $team): array => [
                    'value' => $team['team']->id,
                    'label' => $team['team']->name,
                    'is_default' => $team['is_running'],
                ])->values()->all(),
            ],
        ]);
    }

    /**
     * A row of the list, also the header of the incident page.
     *
     * @param  IncidentState  $state  See IncidentOverview::states().
     * @return array<string, mixed>
     */
    private function summary(array $state): array
    {
        $incident = $state['incident'];
        $notices = $state['notices'];
        $lead = $notices->where('is_dismissed', false)->sortByDesc(fn (Advisory $notice): int => $notice->severity->rank())->first() ?? $notices->first();
        $fixes = $state['fixes'];
        $fixed = collect($fixes)->whereNotNull('version')->count();

        return [
            'id' => $incident->id,
            'title' => $incident->title,
            'lead' => $lead?->title,
            'technology' => ['value' => $incident->technology->value, 'label' => $incident->technology->getLabel()],
            'severity' => $this->severity($incident->severity),
            'first_published' => $this->time($incident->first_published_at),
            'notices_count' => $notices->count(),
            'teams_told_count' => $state['teams']->where('is_told', true)->count(),
            'fixes' => $fixes,
            'fix_status' => match (true) {
                $fixes === [] || $fixed === 0 => ['label' => __('Chưa có bản vá'), 'tone' => 'warning'],
                $fixed < count($fixes) => ['label' => __('Có bản vá một phần'), 'tone' => 'warning'],
                default => ['label' => __('Đã có bản vá'), 'tone' => 'success'],
            },
            'pending_suggestions_count' => $state['pending_suggestions_count'],
            'teams_to_acknowledge_count' => $state['teams']->where('needs_acknowledgement', true)->count(),
        ];
    }

    /**
     * The pending suggestions about the incident: a pre-notice of another incident that may belong to it, or one of
     * its pre-notices that may belong to another incident. Each says where the pre-notice is now, so accepting it
     * moves it only from there.
     *
     * @return list<array<string, mixed>>
     */
    private function suggestions(Incident $incident): array
    {
        return $this->overview->pendingSuggestions($incident)
            ->with(['notice.incident', 'incident'])
            ->orderBy('id')
            ->get()
            ->map(fn (IncidentSuggestion $suggestion): array => [
                'id' => $suggestion->id,
                'reason' => $suggestion->reason->getLabel(),
                'is_incoming' => $suggestion->incident_id === $incident->id,
                'notice' => [
                    'id' => $suggestion->notice->id,
                    'title' => $suggestion->notice->title,
                    'url' => $suggestion->notice->url,
                    'severity' => $this->severity($suggestion->notice->severity),
                    'published' => $this->time($suggestion->notice->published_at),
                ],
                'source' => $suggestion->notice->incident === null ? null : ['id' => $suggestion->notice->incident->id, 'title' => $suggestion->notice->incident->title],
                'target' => ['id' => $suggestion->incident->id, 'title' => $suggestion->incident->title, 'is_current' => $suggestion->incident->merged_into_id === null],
                'created' => $this->time($suggestion->created_at),
            ])
            ->all();
    }

    /**
     * A team of the incident page: its services with their branch, scope and fix, what it was told, its Google Chat
     * thread, its assessment and history, and what the form posts back for it (the exposure hash and the assessment
     * revision shown).
     *
     * @param  TeamState  $team  See IncidentOverview::states().
     * @return array<string, mixed>
     */
    private function teamRow(Incident $incident, array $team): array
    {
        $row = $team['row'];

        return [
            'id' => $team['team']->id,
            'name' => $team['team']->name,
            'services' => array_map(fn (array $service): array => [
                'name' => $service['service'],
                'branch' => $service['branch'],
                'scope' => IncidentScope::from($service['scope'])->getLabel(),
                'is_not_affected' => $service['scope'] === IncidentScope::NotAffected->value,
                'patch' => $service['patch'],
            ], $team['exposure']->services),
            'told_severity' => $team['told_severity'] === null ? null : $this->severity($team['told_severity']),
            'is_told' => $team['is_told'],
            'is_running' => $team['is_running'],
            'is_actionable' => $team['is_actionable'],
            'needs_acknowledgement' => $team['needs_acknowledgement'],
            'thread' => $this->thread($row),
            'assessment' => $row?->impact === null ? null : [
                'impact' => ['value' => $row->impact->value, 'label' => $row->impact->getLabel()],
                'note' => $row->impact_note,
                'by' => $row->acknowledgedBy?->name,
                'at' => $this->time($row->acknowledged_at),
                'is_valid' => $team['is_assessed'],
            ],
            'exposure_hash' => $team['exposure']->hash(),
            'revision' => $row->assessment_revision ?? 0,
            'history' => ($row->events ?? collect())
                ->sortByDesc('id')
                ->take(10)
                ->map(fn (IncidentTeamEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type->getLabel(),
                    'detail' => match ($event->type) {
                        IncidentTeamEventType::Acknowledged => collect([
                            __('Ảnh hưởng: :impact', ['impact' => IncidentImpact::tryFrom((string) ($event->data['impact'] ?? ''))?->getLabel() ?? '—']),
                            $event->data['note'] ?? null,
                        ])->filter()->join(' · '),
                        IncidentTeamEventType::ImpactReset => __('Phạm vi, mức độ hoặc tin của sự kiện đã đổi so với lúc đánh giá.'),
                        IncidentTeamEventType::UpdateSent => Str::limit((string) ($event->data['message'] ?? ''), 120),
                    },
                    'by' => $event->user?->name,
                    'at' => $this->time($event->created_at),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The team's Google Chat thread of the incident, with a link once Google named it.
     *
     * @return array{status: array{label: string, tone: string}, url: string|null}
     */
    private function thread(?IncidentTeam $row): array
    {
        $opener = $row?->openingNotification;

        $status = match (true) {
            $row === null || ! $row->hasThread() => ['label' => __('Chưa có thread'), 'tone' => 'unknown'],
            $row->thread_name !== null => ['label' => __('Đã mở thread'), 'tone' => 'success'],
            $opener instanceof ChatNotification && $opener->failed_at !== null => ['label' => __('Gửi lỗi'), 'tone' => 'danger'],
            default => ['label' => __('Đang gửi'), 'tone' => 'warning'],
        };

        return ['status' => $status, 'url' => $this->threadUrl($row?->thread_name)];
    }

    /**
     * "spaces/AAA/threads/BBB" opens at https://chat.google.com/room/AAA/BBB.
     */
    private function threadUrl(?string $threadName): ?string
    {
        if ($threadName === null || preg_match('#^spaces/([^/]+)/threads/([^/]+)$#', $threadName, $matches) !== 1) {
            return null;
        }

        return 'https://chat.google.com/room/'.rawurlencode($matches[1]).'/'.rawurlencode($matches[2]);
    }

    /**
     * @return array{value: string, label: string}
     */
    private function severity(Severity $severity): array
    {
        return ['value' => $severity->value, 'label' => $severity->getLabel()];
    }

    /**
     * Times are shown in the digest timezone, as in Google Chat.
     */
    private function time(?CarbonInterface $time): ?string
    {
        return $time?->copy()->setTimezone(config('psm.digest.timezone'))->translatedFormat(__('d/m/Y H:i'));
    }

    /**
     * Critical first by default.
     */
    private function severityOrder(): string
    {
        $cases = collect(Severity::cases())->map(fn (Severity $severity): string => "when '{$severity->value}' then {$severity->rank()}")->join(' ');

        return "case severity {$cases} else 0 end";
    }
}

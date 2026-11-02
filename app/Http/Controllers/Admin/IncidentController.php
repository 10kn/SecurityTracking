<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FindingState;
use App\Enums\IncidentImpact;
use App\Enums\IncidentScope;
use App\Enums\IncidentTeamEventType;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Http\Controllers\Controller;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Finding;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamEvent;
use App\Models\IncidentTeamNotice;
use App\Models\Team;
use App\Services\IncidentFindings;
use App\Services\IncidentOverview;
use App\Support\LocalTime;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
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
 * @phpstan-import-type Delivery from IncidentOverview
 */
class IncidentController extends Controller
{
    /**
     * The most related findings the incident page lists.
     */
    private const int FINDINGS_LIMIT = 50;

    /**
     * The most open findings the link to the findings list carries, as many as the list reads from `ids`.
     */
    private const int FINDINGS_LINKED = 200;

    public function __construct(private IncidentOverview $overview, private IncidentFindings $findings) {}

    /**
     * Current incidents, newest first, searched by their title and their pre-notices' titles and CVE / GHSA ids (see
     * Advisory::matching()), filtered by technology, by a team told about them, and on those needing an admin (see
     * IncidentOverview::needsAttention()) or marked as read with "Chưa rõ" (`unknown`, see
     * IncidentOverview::hasUnknownImpact()), for the filtered team only when there is one. The page is read from the
     * database first; only these two filters read the incidents the database cannot rule out, all at once (see
     * IncidentOverview::states()).
     *
     * Incidents whose every pre-notice was dismissed as not security news are left out, unless the list is filtered on
     * them (`dismissed`) or on what needs an admin, which counts them as the Overview does.
     */
    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest($request, ['first_published_at', 'severity'], '-first_published_at');
        $technology = Technology::tryFrom($request->string('technology')->toString());
        $teamId = $request->integer('team') ?: null;
        $isAttention = $request->boolean('attention');
        $isDismissed = $request->boolean('dismissed');
        $isUnknown = $request->boolean('unknown');

        $query = $table->search(Incident::current(), ['title'], fn (Builder $query, string $pattern) => $query->orWhereIn('id', Advisory::matching($pattern)->whereNotNull('incident_id')->select('incident_id')))
            ->when($isDismissed, fn (Builder $query) => $query->dismissed())
            ->when(! $isDismissed && ! $isAttention, fn (Builder $query) => $query->whereNot(fn (Builder $query) => $query->dismissed()))
            ->when($technology !== null, fn (Builder $query) => $query->where('technology', $technology))
            ->when($teamId !== null, fn (Builder $query) => $query->whereIn('id', Advisory::whereKey(IncidentTeamNotice::noticeIdsSentTo($teamId))->whereNotNull('incident_id')->select('incident_id')))
            ->when(
                $table->sort === 'severity',
                fn (Builder $query) => $query->orderByRaw($this->severityOrder().' '.($table->descending ? 'asc' : 'desc')),
                fn (Builder $query) => $query->orderBy('first_published_at', $table->direction()),
            )
            ->orderByDesc('id');

        if ($isAttention || $isUnknown) {
            $candidates = $query
                ->when($isAttention, fn (Builder $query) => $this->overview->mayNeedAttention($query))
                ->when($isUnknown, fn (Builder $query) => $this->overview->mayHaveUnknownImpact($query, $teamId))
                ->get();
            $states = $this->overview->states($candidates)->filter(fn (array $state): bool => (! $isAttention || $this->overview->needsAttention($state, $teamId))
                && (! $isUnknown || $this->overview->hasUnknownImpact($state, $teamId)));
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
            'table' => [...$table->toArray(), 'technology' => $technology?->value, 'team' => $teamId, 'attention' => $isAttention ? 1 : null, 'unknown' => $isUnknown ? 1 : null, 'dismissed' => $isDismissed ? 1 : null],
            'technologies' => array_map(fn (Technology $technology): array => ['value' => $technology->value, 'label' => $technology->getLabel()], Technology::cases()),
            'teams' => Team::orderBy('name')->get()->map(fn (Team $team): array => ['value' => $team->id, 'label' => $team->name])->all(),
        ]);
    }

    /**
     * An incident with its pre-notices, the pending suggestions about it, each team told about it or running its
     * technology, the findings linked to it (see IncidentFindings), and what the merge and urgent update forms need. A
     * merged incident opens the one it went into. The header explains the lead pre-notice (see leadNotice()).
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
        $findings = $this->findings->of($incident, $notices);

        return Inertia::render('incidents/show', [
            'incident' => [
                ...$this->summary($state),
                'planned_release' => LocalTime::format($incident->planned_release_at),
                'affected_branches' => $incident->affected_branches ?? [],
                'unaffected_branches' => $incident->unaffected_branches ?? [],
                'is_exploited' => $notices->contains(fn (Advisory $notice): bool => ! $notice->is_dismissed && $notice->is_exploited === true),
                'known_exploited' => $this->knownExploited($notices->where('is_dismissed', false)->flatMap(fn (Advisory $notice): array => $notice->known_exploited ?? [])->sortByDesc(fn (array $entry): bool => (bool) ($entry['listed'] ?? false))->unique('cve')->sortBy('cve')->values()->all()),
                'merged_from' => Incident::where('merged_into_id', $incident->id)->orderBy('id')->get()->map(fn (Incident $merged): array => ['id' => $merged->id, 'title' => $merged->title])->all(),
                'lead_notice' => $this->leadNotice($this->overview->lead($state)),
            ],
            'notices' => $notices->map(fn (Advisory $notice): array => [
                'id' => $notice->id,
                'title' => $notice->title,
                'url' => $notice->url,
                'severity' => $this->severity($notice->severity),
                'published' => LocalTime::format($notice->published_at),
                'affected_branches' => $notice->affected_branches ?? [],
                'unaffected_branches' => $notice->unaffected_branches ?? [],
                'patch_versions' => $notice->patch_versions ?? [],
                'is_exploited' => $notice->is_exploited === true,
                'exploited_excerpt' => $notice->exploited_basis['excerpt'] ?? null,
                'known_exploited' => $this->knownExploited($notice->known_exploited ?? []),
                'is_dismissed' => $notice->is_dismissed,
                'basis' => $notice->incident_basis?->getLabel(),
                'severity_basis' => $notice->severity_basis?->getLabel(),
            ])->all(),
            'suggestions' => $this->suggestions($incident),
            'teams' => $teams->map(fn (array $team): array => $this->teamRow($incident, $team))->all(),
            'findings' => [
                'items' => $findings->take(self::FINDINGS_LIMIT)->map(fn (array $item): array => $this->findingRow($item['finding'], $item['incident_fix']))->values()->all(),
                'total' => $findings->count(),
                'open_ids' => $findings->pluck('finding')->where('state', FindingState::Open)->pluck('id')->take(self::FINDINGS_LINKED)->values()->all(),
                'open_count' => $findings->pluck('finding')->where('state', FindingState::Open)->count(),
            ],
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
     * A row of the list, also the header of the incident page. The fix status says what the vendor released, never
     * whether a service was upgraded: a branch the vendor confirmed no fix for is missing information, not proof there
     * is none. Whether the incident is too old for reminders is a mark of its own, beside the fix status.
     *
     * The teams told (a card was queued) and those Google Chat accepted a message of the current thread for are
     * counted apart; `has_delivery_failures` when a message of a current thread failed and was not sent again.
     * `is_known_exploited` while CISA KEV still lists a CVE of a pre-notice that is not dismissed; one it removed
     * stays on the incident page only.
     *
     * @param  IncidentState  $state  See IncidentOverview::states().
     * @return array<string, mixed>
     */
    private function summary(array $state): array
    {
        $incident = $state['incident'];
        $notices = $state['notices'];
        $lead = $this->overview->lead($state);
        $fixes = $state['fixes'];
        $confirmed = collect($fixes)->whereNotNull('version')->count();

        return [
            'id' => $incident->id,
            'title' => $incident->title,
            'lead' => $lead?->title,
            'technology' => ['value' => $incident->technology->value, 'label' => $incident->technology->getLabel()],
            'severity' => $this->severity($incident->severity),
            'is_known_exploited' => $notices->where('is_dismissed', false)->contains(fn (Advisory $notice): bool => collect($notice->known_exploited ?? [])->contains(fn (mixed $entry): bool => is_array($entry) && ($entry['listed'] ?? false) === true)),
            'first_published' => LocalTime::format($incident->first_published_at),
            'notices_count' => $notices->count(),
            'teams_told_count' => $state['teams']->where('is_told', true)->count(),
            'teams_delivered_count' => $state['teams']->filter(fn (array $team): bool => $team['delivery']['last_sent_at'] !== null)->count(),
            'has_delivery_failures' => $state['teams']->contains(fn (array $team): bool => $team['delivery']['failures_count'] > 0),
            'fixes' => $fixes,
            'fix_status' => match (true) {
                $confirmed === 0 => ['label' => __('Chưa có thông tin bản vá xác nhận'), 'tone' => 'unknown'],
                $confirmed < count($fixes) => ['label' => __('Hãng có bản vá một phần'), 'tone' => 'warning'],
                default => ['label' => __('Hãng đã có bản vá'), 'tone' => 'neutral'],
            },
            'is_outside_reminder_window' => ! $state['is_within_reminder_window'],
            'pending_suggestions_count' => $state['pending_suggestions_count'],
            'teams_to_acknowledge_count' => $state['teams']->where('needs_acknowledgement', true)->count(),
        ];
    }

    /**
     * The lead pre-notice explained: its summary, the AI one (written in the alerts language, not the viewer's) else
     * the source's; what gave it its severity; its source; and where the classifier stands. A classifier that failed
     * keeps an earlier result only when it had one; one that classified the entry may have read an earlier text.
     *
     * @return array{summary: array{text: string, is_ai: bool}|null, severity_basis: string|null, url: string|null, classifier: array{label: string, tone: string}}|null
     */
    private function leadNotice(?Advisory $notice): ?array
    {
        if ($notice === null) {
            return null;
        }

        return [
            'summary' => match (true) {
                filled($notice->localized_summary) => ['text' => (string) $notice->localized_summary, 'is_ai' => true],
                filled($notice->summary) => ['text' => (string) $notice->summary, 'is_ai' => false],
                default => null,
            },
            'severity_basis' => $notice->severity_basis?->getLabel(),
            'url' => $notice->url,
            'classifier' => match (true) {
                $notice->classification_failed_at !== null && $notice->classified_by !== null => ['label' => __('Phân loại AI lỗi; kết quả AI trước được giữ lại'), 'tone' => 'danger'],
                $notice->classification_failed_at !== null => ['label' => __('Phân loại AI lỗi'), 'tone' => 'danger'],
                $notice->classified_by === null => ['label' => __('AI chưa phân loại'), 'tone' => 'unknown'],
                default => ['label' => __('AI đã từng phân loại'), 'tone' => 'neutral'],
            },
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
                    'published' => LocalTime::format($suggestion->notice->published_at),
                ],
                'source' => $suggestion->notice->incident === null ? null : ['id' => $suggestion->notice->incident->id, 'title' => $suggestion->notice->incident->title],
                'target' => ['id' => $suggestion->incident->id, 'title' => $suggestion->incident->title, 'is_current' => $suggestion->incident->merged_into_id === null],
                'created' => LocalTime::format($suggestion->created_at),
            ])
            ->all();
    }

    /**
     * A team of the incident page: its services with their branch, scope and fix, what it was told, its Google Chat
     * thread and what Google Chat accepted of it, its assessment and history, and what the form posts back for it (the
     * exposure hash and the assessment revision shown).
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
            'thread' => $this->thread($row, $team['delivery']['is_webhook_changed']),
            'delivery' => $team['is_told'] || $team['delivery']['has_messages'] ? $this->delivery($team['delivery'], $row) : null,
            'assessment' => $row?->impact === null ? null : [
                'impact' => ['value' => $row->impact->value, 'label' => $row->impact->getLabel()],
                'note' => $row->impact_note,
                'by' => $row->acknowledgedBy?->name,
                'at' => LocalTime::format($row->acknowledged_at),
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
                        IncidentTeamEventType::ImpactReset => isset($event->data['project'])
                            ? __('Project :project được theo dõi lại.', ['project' => $event->data['project']])
                            : __('Phạm vi, mức độ hoặc tin của sự kiện đã đổi so với lúc đánh giá.'),
                        IncidentTeamEventType::UpdateSent => Str::limit((string) ($event->data['message'] ?? ''), 120),
                    },
                    'by' => $event->user?->name,
                    'at' => LocalTime::format($event->created_at),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * A finding linked to the incident. The versions are those of its last evaluation (`context` is a snapshot): the
     * one the service ran then and the one it asks to upgrade to, which may be later than this incident's fix, shown
     * apart. Read stays open: only an evaluation resolves it.
     *
     * @param  string|null  $incidentFix  The fixed version of this incident the finding lists.
     * @return array<string, mixed>
     */
    private function findingRow(Finding $finding, ?string $incidentFix): array
    {
        $context = $finding->context ?? [];
        $target = $context['latest'] ?? $context['fixed'] ?? null;

        return [
            'id' => $finding->id,
            'title' => $finding->localizedTitle(),
            'rule' => $finding->rule->getLabel(),
            'severity' => $this->severity($finding->severity),
            'service' => $finding->deployment?->service?->name,
            'team' => $finding->team?->name,
            'state' => match (true) {
                $finding->state === FindingState::Resolved => ['label' => __('Đã giải quyết'), 'tone' => 'neutral'],
                $finding->isAcknowledged() => ['label' => __('Mở · Đã đọc'), 'tone' => 'warning'],
                default => ['label' => __('Mở · Chưa đọc'), 'tone' => 'warning'],
            },
            'recorded_version' => is_string($context['version'] ?? null) ? $context['version'] : null,
            'recorded_at' => LocalTime::format($finding->last_seen_at),
            'target_version' => is_string($target) ? $target : null,
            'incident_fix' => $incidentFix,
            'is_resolved' => $finding->state === FindingState::Resolved,
            'resolved' => LocalTime::format($finding->resolved_at),
        ];
    }

    /**
     * The team's Google Chat thread of the incident, with a link once Google named it. A thread of the webhook the team
     * no longer posts to is shown as the old one: the next message opens a new thread.
     *
     * @return array{status: array{label: string, tone: string}, url: string|null, is_old: bool}
     */
    private function thread(?IncidentTeam $row, bool $isWebhookChanged): array
    {
        $opener = $row?->openingNotification;

        $status = match (true) {
            $row === null || ! $row->hasThread() => ['label' => __('Chưa có thread'), 'tone' => 'unknown'],
            $isWebhookChanged => ['label' => __('Thread của webhook cũ'), 'tone' => 'unknown'],
            $row->thread_name !== null => ['label' => __('Đã mở thread'), 'tone' => 'success'],
            $opener instanceof ChatNotification && $opener->failed_at !== null => ['label' => __('Gửi lỗi'), 'tone' => 'danger'],
            default => ['label' => __('Đang gửi'), 'tone' => 'warning'],
        };

        return ['status' => $status, 'url' => $this->threadUrl($row?->thread_name), 'is_old' => $isWebhookChanged && $row?->hasThread() === true];
    }

    /**
     * What Google Chat accepted of the team's current thread: its opener, the message queued last (unless it is the
     * opener), the last one it accepted, and each message that failed and was not sent again, with the command that
     * sends it again. "Đã gửi" says Google Chat accepted the message, not that anyone read it. A team told before
     * incident threads, or with no message in its current thread, shows what cannot be told from here.
     *
     * @param  Delivery  $delivery
     * @return array<string, mixed>
     */
    private function delivery(array $delivery, ?IncidentTeam $row): array
    {
        return [
            'opener' => $this->messageStatus($delivery['opener']),
            'latest' => $delivery['latest']?->id === $delivery['opener']?->id ? null : $this->messageStatus($delivery['latest']),
            'last_sent' => LocalTime::format($delivery['last_sent_at']),
            'failures_count' => $delivery['failures_count'],
            'pending_count' => $delivery['pending_count'],
            'failures' => $delivery['failures']->take(IncidentOverview::FAILURES_SHOWN)->map(fn (ChatNotification $notification): array => [
                'id' => $notification->id,
                'error' => Str::limit((string) $notification->last_error, 160),
                'failed' => LocalTime::format($notification->failed_at),
                'command' => "php artisan psm:resend-notification {$notification->id}",
            ])->values()->all(),
            'limit' => match (true) {
                $delivery['is_webhook_changed'] => __('Webhook của team đã đổi: dữ liệu thread cũ không tính cho webhook hiện tại; tin tiếp theo sẽ mở thread mới.'),
                $delivery['has_messages'] => null,
                $delivery['is_legacy'] => __('Team được báo bằng tin trước khi có thread: không biết từ đây tin đó đã tới chưa.'),
                ($row?->generation ?? 1) > 1 => __('Chưa có dữ liệu chuyển phát trong thread hiện tại (webhook của team đã đổi).'),
                default => __('Chưa có dữ liệu chuyển phát trong thread hiện tại.'),
            },
        ];
    }

    /**
     * @return array{label: string, tone: string, at: string|null}|null
     */
    private function messageStatus(?ChatNotification $notification): ?array
    {
        return match (true) {
            $notification === null => null,
            $notification->sent_at !== null => ['label' => __('Đã gửi'), 'tone' => 'success', 'at' => LocalTime::format($notification->sent_at)],
            $notification->failed_at !== null => ['label' => __('Gửi lỗi'), 'tone' => 'danger', 'at' => LocalTime::format($notification->failed_at)],
            default => ['label' => __('Đang chờ gửi'), 'tone' => 'warning', 'at' => LocalTime::format($notification->created_at)],
        };
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
     * The CISA KEV evidence, with the dates CISA added each CVE and asks it fixed by.
     *
     * @param  list<array<string, mixed>>  $evidence
     * @return list<array{cve: string, added: string, due: string|null, listed: bool}>
     */
    private function knownExploited(array $evidence): array
    {
        $date = fn (?string $date): ?string => $date === null ? null : Carbon::parse($date)->translatedFormat(__('d/m/Y'));

        return array_map(fn (array $entry): array => [
            'cve' => (string) $entry['cve'],
            'added' => (string) $date($entry['date_added']),
            'due' => $date($entry['due_date'] ?? null),
            'listed' => (bool) ($entry['listed'] ?? false),
        ], $evidence);
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

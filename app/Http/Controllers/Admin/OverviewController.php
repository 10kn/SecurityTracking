<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ResolveNoticeCves;
use App\Actions\SyncKnownExploitedVulnerabilities;
use App\Enums\Ecosystem;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Http\Controllers\Controller;
use App\Models\ChatNotification;
use App\Models\Finding;
use App\Models\Incident;
use App\Models\Service;
use App\Models\SourceHeartbeat;
use App\Models\Team;
use App\Services\IncidentOverview;
use App\Services\Packages\VulnerabilityMatcher;
use App\Services\Repositories\RepositoryReader;
use App\Support\LocalTime;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin home. It answers two questions only: is monitoring silent because of an error (sources over their
 * threshold, Google Chat messages not delivered, services not fully evaluated), and where does work start today
 * (priority findings, incidents needing an admin). Each count opens the filtered list.
 *
 * Each block is read on its own: one that cannot be read is null, which the page shows as such, never as nothing to
 * report, and the others still show. Nothing is guessed from timestamps: whether a source ran is what its heartbeat
 * recorded, and whether Google Chat works is what the message log recorded.
 *
 * @phpstan-import-type IncidentState from IncidentOverview
 */
class OverviewController extends Controller
{
    /**
     * Rows each list shows; its count opens the whole filtered list.
     */
    private const int LIST_LIMIT = 3;

    /**
     * Destinations the Google Chat line names; the others are counted.
     */
    private const int DESTINATION_LIMIT = 5;

    /**
     * Failed message ids a destination names in its resend commands.
     */
    private const int RESEND_LIMIT = 5;

    /**
     * @param  iterable<VulnerabilityMatcher>  $matchers
     */
    public function __construct(
        private IncidentOverview $overview,
        private RepositoryReader $repositoryReader,
        #[Tag(VulnerabilityMatcher::class)] private iterable $matchers,
    ) {}

    public function __invoke(): Response
    {
        $checkedAt = LocalTime::of(now());

        return Inertia::render('overview/index', [
            'checked' => [
                'date' => Str::ucfirst($checkedAt->translatedFormat(__('l, d/m/Y'))),
                'time' => LocalTime::timeOfDay($checkedAt),
            ],
            'sources' => $this->attempt(fn (): array => $this->sources()),
            'delivery' => $this->attempt(fn (): array => $this->delivery()),
            'coverage' => $this->attempt(fn (): array => ['count' => Service::notFullyEvaluated()->count()]),
            'findings' => $this->attempt(fn (): array => $this->priorityFindings()),
            'incidents' => $this->attempt(fn (): array => $this->incidents()),
        ]);
    }

    /**
     * Every source that recorded a heartbeat, those over their threshold first.
     *
     * @return list<array<string, mixed>>
     */
    private function sources(): array
    {
        return SourceHeartbeat::orderBy('source')
            ->get()
            ->sortBy(fn (SourceHeartbeat $heartbeat): int => $heartbeat->isStale() ? 0 : 1)
            ->map(fn (SourceHeartbeat $heartbeat): array => [
                'source' => $heartbeat->source,
                'name' => $heartbeat->localizedName(),
                'is_stale' => $heartbeat->isStale(),
                'last_success' => $heartbeat->last_success_at?->diffForHumans(),
                'threshold' => CarbonInterval::minutes($heartbeat->threshold_minutes)->cascade()->forHumans(),
                'error' => $heartbeat->last_error,
                'last_failure' => LocalTime::format($heartbeat->last_failure_at),
                'consequence' => $this->consequence($heartbeat),
                'technology' => $heartbeat->catalogTechnology()?->value,
            ])
            ->values()
            ->all();
    }

    /**
     * What is missed while the source is over its threshold.
     */
    private function consequence(SourceHeartbeat $heartbeat): ?string
    {
        $matcher = collect($this->matchers)->first(fn (VulnerabilityMatcher $matcher): bool => $matcher->sourceKey() === $heartbeat->source);
        $noticeFeed = collect(Technology::cases())->first(fn (Technology $technology): bool => $technology->noticeSourceKey() === $heartbeat->source);

        return match (true) {
            $heartbeat->catalogTechnology() !== null => __('Runtime :technology được coi là Thiếu dữ liệu cho tới khi danh mục đồng bộ lại.', ['technology' => $heartbeat->catalogTechnology()->getLabel()]),
            $noticeFeed !== null => __('Có thể bỏ sót tin bảo mật sớm của :source.', ['source' => $noticeFeed->releaseSourceLabel()]),
            $matcher !== null => __('Có thể bỏ sót lỗ hổng mới của package :ecosystems. Finding đang mở vẫn giữ nguyên.', [
                'ecosystems' => collect($matcher->ecosystems())->map(fn (Ecosystem $ecosystem): string => $ecosystem->getLabel())->join(', '),
            ]),
            $heartbeat->source === SyncKnownExploitedVulnerabilities::SOURCE => __('Có thể bỏ sót lỗ hổng đang bị khai thác mà CISA KEV mới thêm.'),
            $heartbeat->source === ResolveNoticeCves::SOURCE => __('Tin GitHub chưa có mã CVE chưa được đối chiếu với CISA KEV.'),
            $heartbeat->source === $this->repositoryReader->sourceKey() => __('Lockfile không được đọc lại từ repository; package mới đổi có thể chưa được quét.'),
            default => null,
        };
    }

    /**
     * The Google Chat destinations messages do not reach: the admin space first, then the teams by name. A message
     * counts when it failed for good or has waited longer than `stuck_after_minutes`, unless a later message to the
     * same destination was delivered since (the webhook works again; the failure was reported with its resend
     * command), or an admin's "Không" withholds it (see ChatNotification::isWithheldByAssessment()): it is not to be
     * sent, so it is no delivery problem and offers no resend command. A destination without a webhook counts too.
     *
     * @return array{destinations: list<array<string, mixed>>, hidden_count: int, last_sent: string|null}
     */
    private function delivery(): array
    {
        $undelivered = ChatNotification::whereNull('sent_at')
            ->where(fn (Builder $query) => $query->whereNotNull('failed_at')->orWhere('created_at', '<', now()->subMinutes(config('psm.alerts.stuck_after_minutes'))))
            ->whereNotExists(fn (QueryBuilder $query) => $query
                ->from('chat_notifications as delivered')
                ->whereRaw('delivered.team_id is not distinct from chat_notifications.team_id')
                ->whereColumn('delivered.sent_at', '>', 'chat_notifications.created_at'))
            ->orderBy('id')
            ->get(['id', 'team_id', 'kind', 'incident_team_id', 'created_at', 'failed_at', 'last_error'])
            ->reject(fn (ChatNotification $notification): bool => $notification->isWithheldByAssessment())
            ->groupBy(fn (ChatNotification $notification): int => $notification->team_id ?? 0);

        $adminSpace = $undelivered->get(0, collect());
        $isAdminConfigured = Team::googleChatSpaceOf(config('psm.alerts.admin_webhook')) !== null;

        $destinations = Team::orderBy('name')
            ->get()
            ->filter(fn (Team $team): bool => $undelivered->has($team->id) || blank($team->google_chat_webhook_url))
            ->map(fn (Team $team): array => $this->destination($team, $undelivered->get($team->id, collect()), filled($team->google_chat_webhook_url)))
            ->values();

        if ($adminSpace->isNotEmpty() || ! $isAdminConfigured) {
            $destinations->prepend($this->destination(null, $adminSpace, $isAdminConfigured));
        }

        return [
            'destinations' => $destinations->take(self::DESTINATION_LIMIT)->all(),
            'hidden_count' => max(0, $destinations->count() - self::DESTINATION_LIMIT),
            'last_sent' => LocalTime::format(ChatNotification::whereNotNull('sent_at')->orderByDesc('sent_at')->value('sent_at')),
        ];
    }

    /**
     * A destination of the Google Chat line: the admin space without a team.
     *
     * @param  Collection<int, ChatNotification>  $messages  Its messages not delivered, oldest first.
     * @return array<string, mixed>
     */
    private function destination(?Team $team, Collection $messages, bool $hasWebhook): array
    {
        $failedIds = $messages->whereNotNull('failed_at')->pluck('id');

        return [
            'team' => $team === null ? null : ['id' => $team->id, 'name' => $team->name],
            'has_webhook' => $hasWebhook,
            'count' => $messages->count(),
            'since' => LocalTime::format($messages->first()?->created_at, __('d/m H:i')),
            'error' => $messages->whereNotNull('last_error')->last()?->last_error,
            'stuck_count' => $messages->whereNull('failed_at')->count(),
            'resend_ids' => $failedIds->take(self::RESEND_LIMIT)->values()->all(),
            'resend_hidden_count' => max(0, $failedIds->count() - self::RESEND_LIMIT),
        ];
    }

    /**
     * The open findings to start with (see Finding::priority()), in the order to handle them.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function priorityFindings(): array
    {
        $findings = Finding::open()->priority();

        return [
            'count' => (clone $findings)->count(),
            'items' => $findings
                ->orderByPriority()
                ->with(['deployment.service', 'team'])
                ->limit(self::LIST_LIMIT)
                ->get()
                ->map(fn (Finding $finding): array => [
                    'id' => $finding->id,
                    'severity' => $this->severity($finding->severity),
                    'title' => $finding->localizedTitle(),
                    'service' => $finding->deployment->service->name,
                    'team' => $finding->team->name,
                    'is_known_exploited' => $finding->isKnownExploited(),
                    'is_read' => $finding->isAcknowledged(),
                    'deadline' => $finding->sla_due_at === null ? null : [
                        'label' => $finding->sla_due_at->isPast()
                            ? __('Quá :time', ['time' => $finding->sla_due_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE)])
                            : __('Còn :time', ['time' => $finding->sla_due_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE)]),
                        'is_overdue' => $finding->sla_due_at->isPast(),
                    ],
                ])
                ->all(),
        ];
    }

    /**
     * The current incidents needing an admin (see IncidentOverview::needsAttention()), newest first as in the
     * incidents list, with why.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function incidents(): array
    {
        $candidates = $this->overview->mayNeedAttention(Incident::current()->orderByDesc('first_published_at')->orderByDesc('id'))->get();
        $states = $this->overview->states($candidates)->filter(fn (array $state): bool => $this->overview->needsAttention($state));

        return [
            'count' => $states->count(),
            'items' => $candidates
                ->filter(fn (Incident $incident): bool => $states->has($incident->id))
                ->take(self::LIST_LIMIT)
                ->map(fn (Incident $incident): array => $this->incident($states->get($incident->id)))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  IncidentState  $state
     * @return array<string, mixed>
     */
    private function incident(array $state): array
    {
        $incident = $state['incident'];
        $waiting = $state['teams']->where('needs_acknowledgement', true);
        $reassess = $waiting->filter(fn (array $team): bool => $team['row']?->impact !== null)->count();

        return [
            'id' => $incident->id,
            'title' => $this->overview->lead($state)->title ?? $incident->title,
            'severity' => $this->severity($incident->severity),
            'first_published' => LocalTime::date($incident->first_published_at),
            'reasons' => array_values(array_filter([
                $waiting->count() - $reassess > 0 ? __(':count team cần đánh giá ảnh hưởng', ['count' => $waiting->count() - $reassess]) : null,
                $reassess > 0 ? __(':count team cần đánh giá lại ảnh hưởng', ['count' => $reassess]) : null,
                $state['pending_suggestions_count'] > 0 ? __(':count gợi ý gộp đang chờ', ['count' => $state['pending_suggestions_count']]) : null,
            ])),
        ];
    }

    /**
     * The block, or null when it cannot be read; the error is reported.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $block
     * @return TValue|null
     */
    private function attempt(Closure $block): mixed
    {
        return rescue($block, null);
    }

    /**
     * @return array{value: string, label: string}
     */
    private function severity(Severity $severity): array
    {
        return ['value' => $severity->value, 'label' => $severity->getLabel()];
    }
}

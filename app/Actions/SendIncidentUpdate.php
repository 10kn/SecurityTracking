<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Enums\ChatThreadRole;
use App\Enums\IncidentAlertReason;
use App\Enums\IncidentTeamEventType;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamNotice;
use App\Models\Runtime;
use App\Models\Team;
use App\Models\User;
use App\Services\GoogleChatCards;
use App\Support\IncidentLock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use InvalidArgumentException;

/**
 * An admin's urgent update about an incident, e.g. a workaround or a vendor call, posted as a reply in each team's
 * thread of the incident, or as a full card that opens the thread when the team has none (see IncidentTeam). By
 * default it goes to every team told about the incident; the admin may name the teams instead.
 *
 * Each request has its own id: a message is queued once per team and request, so running the same request again
 * (a retried form, a double click) sends nothing twice, while a new update is a new request. The message records who
 * asked for it (`user:{id}`, or `cli` for psm:incident-update), and the team's history of the incident records that
 * it was queued (see IncidentTeamEvent); whether it was delivered is the message's own state. An update never marks
 * the incident as read for a team.
 */
class SendIncidentUpdate
{
    use Localizable;

    public function __construct(private QueueChatNotification $queueChatNotification, private GoogleChatCards $cards) {}

    /**
     * @param  list<int>  $teamIds  The teams to tell; none means every team told about the incident.
     * @param  string|null  $requestId  The id of the admin's request; a new one when none is given.
     * @return array{request_id: string, queued: int}
     *
     * @throws InvalidArgumentException When the incident was merged into another or has no pre-notice.
     */
    public function handle(Incident $incident, string $message, array $teamIds = [], ?User $actor = null, ?string $requestId = null): array
    {
        $requestId ??= (string) Str::uuid();
        $message = Str::limit(trim($message), 2000);

        if ($message === '') {
            throw new InvalidArgumentException('The update has no text.');
        }

        $queued = DB::transaction(function () use ($incident, $message, $teamIds, $actor, $requestId): int {
            IncidentLock::take([$incident->technology]);

            $incident = Incident::whereKey($incident->id)->lockForUpdate()->first();

            if ($incident === null || $incident->merged_into_id !== null) {
                throw new InvalidArgumentException('Only a current incident can be updated.');
            }

            $notices = $incident->notices()->orderBy('is_dismissed')->orderBy('published_at')->orderBy('id')->get();

            if ($notices->isEmpty()) {
                throw new InvalidArgumentException('The incident has no pre-notice.');
            }

            $memberIds = $notices->modelKeys();
            $teams = $teamIds === []
                ? Team::orderBy('id')->get()->filter(fn (Team $team): bool => IncidentTeamNotice::severityToldTo($team->id, $memberIds) !== null)
                : Team::whereKey($teamIds)->orderBy('id')->get();

            return $teams->sum(fn (Team $team): int => (int) $this->queue($incident, $notices->where('is_dismissed', false)->values()->whenEmpty(fn () => $notices), $team, $message, $actor, $requestId));
        });

        return ['request_id' => $requestId, 'queued' => $queued];
    }

    /**
     * @param  Collection<int, Advisory>  $notices  The incident's pre-notices that were not dismissed, oldest first.
     */
    private function queue(Incident $incident, Collection $notices, Team $team, string $message, ?User $actor, string $requestId): bool
    {
        $runtimes = Runtime::where('technology', $incident->technology)
            ->monitored()
            ->whereHas('deployment.service.project', fn ($query) => $query->where('team_id', $team->id))
            ->with('deployment.service.project')
            ->get();

        [$thread] = IncidentTeam::lockFor($incident, $team);
        $thread->moveTo($team->google_chat_webhook_url);
        $role = $thread->nextRole();

        $lead = $notices->sortByDesc(fn (Advisory $notice): int => $notice->severity->rank())->first();
        $others = $notices->reject(fn (Advisory $notice): bool => $notice->is($lead))->sortByDesc(fn (Advisory $notice): int => $notice->severity->rank())->take(config('psm.alerts.max_listed_items'))->values();

        [$opening, $reply] = $this->withLocale(config('psm.alerts.locale'), fn (): array => [
            $this->cards->preNotice($team, $incident, $lead, $others, $runtimes, null, [__('Cập nhật từ admin: :message', ['message' => $message])]),
            $this->cards->incidentUpdate($team, $incident, $lead, [__('Cập nhật từ admin')], $runtimes, __('Đọc cập nhật và xử lý theo hướng dẫn.'), adminMessage: $message),
        ]);

        $notification = $this->queueChatNotification->handle($team, ChatNotificationKind::IncidentUpdate, "incident_update:{$requestId}:{$team->id}", $role === ChatThreadRole::Reply ? $reply : $opening, attributes: [
            ...$thread->threadAttributes($role),
            'opening_payload' => $role === ChatThreadRole::Reply ? $opening : null,
            'requested_by' => $actor === null ? 'cli' : "user:{$actor->id}",
        ]);

        if ($notification === null) {
            return false;
        }

        $shown = $role === ChatThreadRole::Reply ? collect([$lead]) : collect([$lead, ...$others]);

        IncidentTeamNotice::insertOrIgnore($shown->map(fn (Advisory $notice): array => [
            'team_id' => $team->id,
            'advisory_id' => $notice->id,
            'severity' => $notice->severity->value,
            'reason' => IncidentAlertReason::Manual->value,
            'chat_notification_id' => $notification->id,
            'created_at' => now(),
        ])->all());

        if ($role === ChatThreadRole::Opener) {
            $thread->told_confirmed_branches = $incident->confirmedBranches($runtimes);
        }

        $thread->recordQueued($notification);

        $thread->events()->create([
            'type' => IncidentTeamEventType::UpdateSent,
            'user_id' => $actor?->id,
            'data' => ['request_id' => $requestId, 'chat_notification_id' => $notification->id, 'message' => $message],
        ]);

        return true;
    }
}

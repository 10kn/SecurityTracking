<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Enums\ChatThreadRole;
use App\Enums\IncidentAlertReason;
use App\Enums\IncidentScope;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamNotice;
use App\Models\IncidentTeamPatch;
use App\Models\Runtime;
use App\Models\Team;
use App\Services\AlertPolicy;
use App\Services\GoogleChatCards;
use App\Support\IncidentLock;
use App\Support\Version;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * Sends "Cảnh báo chuẩn bị" (handover 5.2) per incident, in one Google Chat thread per incident and team (see
 * IncidentTeam): every team that runs the technology gets one card per incident, listing its services on that
 * technology with whether the vendor said their branch is affected. That card opens the thread. Only pre-notices
 * published within `psm.notices.relevant_days` open one, and never those a feed listed on its first poll (see
 * SyncNoticeFeed). A team that starts running the technology later gets the card on a later run while the incident is
 * still within that window. Running it again sends nothing new.
 *
 * An incident is sent right away when one of its pre-notices is (Critical, High or of unknown severity, see
 * AlertPolicy): a Low one never hides one the classifier has not rated. Incidents of Medium and Low pre-notices only
 * wait for the daily digest, and pre-notices the classifier dismissed as not security news do not count. Pre-notices
 * only notify: they never open or escalate findings, since the vendor rarely says which branches are affected.
 *
 * A team told about an incident hears about it again only when it has to act on something new, whenever the incident
 * began, while it still runs a branch the incident may affect: the vendor confirms the fix for a branch it runs
 * ("Đã có bản vá", see Incident::confirmedPatches()), says the incident is exploited, confirms a branch of the team as
 * affected that it was not told about, including one the vendor had ruled out or stopped naming, or the incident's
 * severity rises above what its pre-notices were sent at. All of them in one run make one compact reply in the
 * thread; a team without a ready thread (a card from before threads, a new space, an opener that failed) gets a full
 * card that opens one. News that only adds CVEs, moves the planned release or rewords the text sends nothing. A fix
 * is also the first card of an incident the team had not heard of. A security release no pre-notice names is not a
 * fix yet: it only appears in the digest.
 *
 * What each message tells is recorded in the transaction that queues it (`incident_team_notices`,
 * `incident_team_patches`, the told branches and exploitation on IncidentTeam), linked to the message, as having
 * been asked to be sent: a message that fails stays failed for psm:resend-notification, and is not queued again.
 */
class SendPreNoticeAlerts
{
    use Localizable;

    public function __construct(
        private AlertPolicy $policy,
        private QueueChatNotification $queueChatNotification,
        private GoogleChatCards $cards,
        private AssignNoticeToIncident $assignNoticeToIncident,
        private CoverPatchedFindings $coverPatchedFindings,
    ) {}

    /**
     * Incidents are handled most severe and newest first, and the runtimes of a technology are read once per run. An
     * incident whose alert fingerprint (see fingerprint()) is the one stored by the last full run is skipped: nothing it
     * reads changed, so it has nothing new to send.
     *
     * @param  bool  $holdUnclassified  Leaves out pre-notices below Critical the classifier has not read yet: they wait
     *                                  for it, as it may raise them, while Critical ones go out right away. Such a run
     *                                  sends a part of what a full run would, so it never stores a fingerprint.
     * @return int The number of messages queued.
     */
    public function handle(bool $holdUnclassified = false): int
    {
        $this->assignNoticeToIncident->assignUnassigned();

        $alertableIds = Advisory::alertable()->whereNotNull('incident_id')->distinct()->pluck('incident_id');
        $toldIds = Advisory::whereKey(IncidentTeamNotice::toldNoticeIds())->whereNotNull('incident_id')->distinct()->pluck('incident_id');
        $runtimes = [];

        return Incident::current()
            ->whereKey($alertableIds->merge($toldIds)->unique()->values())
            ->get()
            ->sort(fn (Incident $a, Incident $b): int => [$b->severity->rank(), $b->first_published_at?->getTimestamp() ?? 0, $b->id] <=> [$a->severity->rank(), $a->first_published_at?->getTimestamp() ?? 0, $a->id])
            ->sum(function (Incident $incident) use (&$runtimes, $holdUnclassified): int {
                $runtimes[$incident->technology->value] ??= $this->runtimesOf($incident->technology);

                return $this->send($incident, $runtimes[$incident->technology->value], $holdUnclassified);
            });
    }

    /**
     * The runtimes of the technology on current deployments, by team, and a signature of what the alerts read from
     * them.
     *
     * @return array{by_team: Collection<int|string, EloquentCollection<int, Runtime>>, signature: string}
     */
    private function runtimesOf(Technology $technology): array
    {
        $runtimes = Runtime::where('technology', $technology)
            ->whereHas('deployment', fn ($query) => $query->where('is_current', true))
            ->with('deployment.service.project.team')
            ->orderBy('id')
            ->get();

        return [
            'by_team' => $runtimes->groupBy(fn (Runtime $runtime): int => $runtime->deployment->service->project->team_id),
            'signature' => hash('sha256', (string) json_encode($runtimes->map(fn (Runtime $runtime): array => [
                $runtime->id,
                $runtime->deployment->service->project->team_id,
                $runtime->branch,
                $runtime->version,
                $runtime->raw_version,
                $runtime->status->value,
            ])->all())),
        ];
    }

    /**
     * The incident row is locked while its messages are queued, so an admin cannot merge or split it meanwhile, then
     * each team's thread row. The technology's IncidentLock comes first, so an evaluation storing a "thiếu bản vá"
     * finding waits for the "Đã có bản vá" card that stands for it, or the card waits for the finding (see SyncFindings).
     *
     * A team sent a card about the incident gets its updates whatever the incident's severity now; a team never sent
     * one gets a first card only while the incident is fresh and urgent.
     *
     * @param  array{by_team: Collection<int|string, EloquentCollection<int, Runtime>>, signature: string}  $technologyRuntimes
     */
    private function send(Incident $incident, array $technologyRuntimes, bool $holdUnclassified): int
    {
        return DB::transaction(function () use ($incident, $technologyRuntimes, $holdUnclassified): int {
            IncidentLock::take([$incident->technology]);

            $incident = Incident::whereKey($incident->id)->lockForUpdate()->first();

            if ($incident === null || $incident->merged_into_id !== null) {
                return 0;
            }

            $all = $incident->notices()->orderBy('published_at')->orderBy('id')->get();
            $patches = $incident->confirmedPatches($all);
            $since = now()->subDays(config('psm.notices.relevant_days'));
            $inputs = $this->inputsFingerprint($incident, $all, $patches, $since, $technologyRuntimes['signature']);

            if ($incident->alert_fingerprint === $this->fingerprint($incident, $all, $inputs)) {
                return 0;
            }

            $notices = $this->countingNotices($all->reject(fn (Advisory $notice): bool => $notice->is_dismissed || $notice->is_backfill), $holdUnclassified);
            $fresh = $notices->filter(fn (Advisory $notice): bool => $notice->published_at !== null && $notice->published_at->gte($since))->values();
            $isFresh = $this->isUrgent($fresh);
            $memberIds = $all->modelKeys();
            $patches = $notices->isEmpty() ? [] : $patches;
            $queued = 0;

            foreach ($notices->isEmpty() ? [] : $technologyRuntimes['by_team'] as $teamId => $runtimes) {
                $wasTold = IncidentTeamNotice::wasSentTo($teamId, $memberIds);

                if (! $wasTold && ! $isFresh) {
                    continue;
                }

                $team = $runtimes->first()->deployment->service->project->team;
                [$thread, $isNewThread] = IncidentTeam::lockFor($incident, $team);
                $thread->moveTo($team->google_chat_webhook_url);
                $duePatches = $this->duePatches($teamId, $runtimes, $patches, $memberIds);

                $queued += (int) ($wasTold
                    ? $this->queueUpdate($team, $incident, $notices, $runtimes, $thread, $isNewThread, IncidentTeamNotice::severityToldTo($teamId, $memberIds) ?? Severity::Unknown, $duePatches, $patches)
                    : $this->queueFirstCard($team, $incident, $fresh, $runtimes, $thread, $duePatches, $patches));
            }

            if (! $holdUnclassified) {
                $incident->forceFill(['alert_fingerprint' => $this->fingerprint($incident, $all, $inputs)])->saveQuietly();
            }

            return $queued;
        });
    }

    /**
     * The alert fingerprint: what the run read to decide (see inputsFingerprint()) and what each team was told. What
     * the teams were told is only written under the technology's IncidentLock, which the run holds, so reading it
     * again once the run queued its messages sees the run's own writes and nothing else.
     *
     * @param  Collection<int, Advisory>  $notices  Every pre-notice of the incident.
     */
    private function fingerprint(Incident $incident, Collection $notices, string $inputs): string
    {
        $memberIds = $notices->pluck('id')->all();

        return hash('sha256', (string) json_encode([
            $inputs,
            IncidentTeamNotice::whereIn('advisory_id', $memberIds)->max('id'),
            IncidentTeamPatch::whereIn('advisory_id', $memberIds)->max('id'),
            IncidentTeam::where('incident_id', $incident->id)->orderBy('id')->get()->map(fn (IncidentTeam $thread): array => [$thread->team_id, $thread->told_confirmed_branches, $thread->exploited_told_at?->getTimestamp(), $thread->last_notification_id])->all(),
        ]));
    }

    /**
     * A hash of the inputs the run decides on, taken from the very snapshot it decides on, so whatever is committed
     * afterwards (a catalog release, a classification) changes the next fingerprint: what decides the alerts of each
     * pre-notice, which of them are fresh now (the only input that changes with time alone), the confirmed fixes, the
     * runtimes of the technology and the alert settings. Values, not update times, so two edits within one second are
     * told apart.
     *
     * @param  Collection<int, Advisory>  $notices  Every pre-notice of the incident.
     * @param  array<string, array{version: string, notice: Advisory}>  $patches
     */
    private function inputsFingerprint(Incident $incident, Collection $notices, array $patches, CarbonInterface $since, string $runtimeSignature): string
    {
        return hash('sha256', (string) json_encode([
            $notices->sortBy('id')->map(fn (Advisory $notice): array => [
                $notice->id,
                $notice->severity->value,
                $notice->is_dismissed,
                $notice->is_backfill,
                $notice->classified_by,
                $notice->published_at?->getTimestamp(),
                $notice->published_at !== null && $notice->published_at->gte($since),
                $notice->affected_branches,
                $notice->unaffected_branches,
                $notice->patch_versions,
                $notice->is_exploited,
                $notice->exploited_basis['excerpt'] ?? null,
            ])->values()->all(),
            [$incident->affected_branches, $incident->unaffected_branches],
            collect($patches)->map(fn (array $patch): string => "{$patch['notice']->id}:{$patch['version']}")->all(),
            $runtimeSignature,
            [config('psm.alerts.immediate_severities'), config('psm.notices.relevant_days'), config('psm.alerts.max_listed_items')],
        ]));
    }

    /**
     * The first card of an incident for a team: the pre-notices that count now, with the fixes due, and that the
     * incident is exploited when it is.
     *
     * @param  Collection<int, Advisory>  $notices  The incident's fresh pre-notices that count, oldest first.
     * @param  Collection<int, Runtime>  $runtimes
     * @param  array<string, array{version: string, notice: Advisory}>  $duePatches
     * @param  array<string, array{version: string, notice: Advisory}>  $patches
     */
    private function queueFirstCard(Team $team, Incident $incident, Collection $notices, Collection $runtimes, IncidentTeam $thread, array $duePatches, array $patches): bool
    {
        $exploited = $notices->first(fn (Advisory $notice): bool => $notice->is_exploited);
        $changes = $exploited === null ? [] : [IncidentAlertReason::Exploited->value => $this->exploitedLine($exploited)];
        $reason = $duePatches === [] ? IncidentAlertReason::New : IncidentAlertReason::PatchConfirmed;

        return $this->queue($team, $incident, $notices, $runtimes, $thread, null, $changes, $reason, $duePatches, $patches, null);
    }

    /**
     * An update for a team told about the incident, when it has something new to act on (see the class). A thread row
     * made for a team told before threads takes what the team runs on confirmed branches as already told.
     *
     * @param  Collection<int, Advisory>  $notices  The incident's pre-notices that count, oldest first.
     * @param  Collection<int, Runtime>  $runtimes
     * @param  array<string, array{version: string, notice: Advisory}>  $duePatches
     * @param  array<string, array{version: string, notice: Advisory}>  $patches
     */
    private function queueUpdate(Team $team, Incident $incident, Collection $notices, Collection $runtimes, IncidentTeam $thread, bool $isNewThread, Severity $toldSeverity, array $duePatches, array $patches): bool
    {
        $confirmed = $incident->confirmedBranches($runtimes);
        $told = $isNewThread ? $confirmed : array_values(array_intersect($thread->told_confirmed_branches ?? [], $confirmed));
        $extended = array_values(array_diff($confirmed, $told));
        $isAffected = $runtimes->contains(fn (Runtime $runtime): bool => $incident->scopeFor($runtime) !== IncidentScope::NotAffected);
        $severity = Incident::highestSeverity($notices);
        $exploited = $notices->first(fn (Advisory $notice): bool => $notice->is_exploited);
        $isRaised = $isAffected && $toldSeverity->rank() < $severity->rank();

        $changes = array_filter([
            IncidentAlertReason::PatchConfirmed->value => $duePatches === [] ? null : __('Hãng đã xác nhận bản vá :versions.', ['versions' => collect($duePatches)->pluck('version')->join(', ')]),
            IncidentAlertReason::Exploited->value => $isAffected && $exploited !== null && $thread->exploited_told_at === null ? $this->exploitedLine($exploited) : null,
            IncidentAlertReason::ScopeExtended->value => $extended === [] ? null : __('Hãng xác nhận thêm nhánh bị ảnh hưởng: :branches.', ['branches' => implode(', ', $extended)]),
            IncidentAlertReason::SeverityRise->value => $isRaised ? __('Mức độ tăng từ :from lên :to.', ['from' => $toldSeverity->getLabel(), 'to' => $severity->getLabel()]) : null,
        ]);

        if ($changes === []) {
            if ($told !== ($thread->told_confirmed_branches ?? [])) {
                $thread->update(['told_confirmed_branches' => $told]);
            }

            return false;
        }

        return $this->queue($team, $incident, $notices, $runtimes, $thread, $isRaised ? $toldSeverity : null, $changes, IncidentAlertReason::from(array_key_first($changes)), $duePatches, $patches, $extended);
    }

    /**
     * Queues the message in the team's thread: a compact reply while the thread is there (see IncidentTeam::nextRole()),
     * else the full card that opens it; a reply keeps the full card to open the thread with if it has to. The card
     * leads with the most severe pre-notice and lists the others up to `psm.alerts.max_listed_items`, most severe
     * first. Only the pre-notices it shows are recorded as told. With fixes due, it is the "Đã có bản vá" card, which
     * also stands for the team's "thiếu bản vá" findings of those branches (see CoverPatchedFindings), with the
     * deadline they share: that of the incident's severity from now, or the earliest of those findings.
     *
     * @param  Collection<int, Advisory>  $notices
     * @param  Collection<int, Runtime>  $runtimes  One team's runtimes of the technology.
     * @param  Severity|null  $previousSeverity  What the team was told before, when the incident rose since.
     * @param  array<string, string>  $changes  What changed, by IncidentAlertReason, most important first.
     * @param  array<string, array{version: string, notice: Advisory}>  $duePatches
     * @param  array<string, array{version: string, notice: Advisory}>  $patches  Every fix of the incident (see Incident::confirmedPatches()).
     * @param  list<string>|null  $extended  The branches newly confirmed as affected, for an update; null for a first card.
     */
    private function queue(Team $team, Incident $incident, Collection $notices, Collection $runtimes, IncidentTeam $thread, ?Severity $previousSeverity, array $changes, IncidentAlertReason $reason, array $duePatches, array $patches, ?array $extended): bool
    {
        $isUpdate = $extended !== null;
        $lead = $notices->sortByDesc(fn (Advisory $notice): int => $notice->severity->rank())->first();
        $others = $notices->reject(fn (Advisory $notice): bool => $notice->is($lead))->sortByDesc(fn (Advisory $notice): int => $notice->severity->rank())->take(config('psm.alerts.max_listed_items'))->values();
        $severity = Incident::highestSeverity($notices);
        $role = $thread->nextRole();
        $isReply = $isUpdate && $role === ChatThreadRole::Reply;
        $shown = $isReply ? collect([$lead]) : collect([$lead, ...$others]);
        $versions = collect($duePatches)->map(fn (array $patch, string $branch): string => "{$branch}:{$patch['version']}")->sort()->join(',');
        $covered = collect($duePatches)->map(fn (array $patch, string $branch) => $this->coverPatchedFindings->findingsFor($team->id, $incident->technology, $branch, $patch['version']));
        $deadline = $this->coverPatchedFindings->deadlineFor($severity, new EloquentCollection($covered->collapse()->all()));
        $versionsOf = fn (array $patches): array => collect($patches)->map(fn (array $patch): string => $patch['version'])->all();
        $cardChanges = array_values(array_intersect_key($changes, array_flip([IncidentAlertReason::Exploited->value, IncidentAlertReason::ScopeExtended->value])));

        $opening = $this->withLocale(config('psm.alerts.locale'), fn (): array => $duePatches === []
            ? $this->cards->preNotice($team, $incident, $lead, $others, $runtimes, $previousSeverity, $cardChanges)
            : $this->cards->patchAvailable($team, $incident, $lead, $others, $runtimes, $versionsOf($duePatches), $versionsOf($patches), $previousSeverity, $deadline, $cardChanges));

        $payload = $isReply
            ? $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->incidentUpdate($team, $incident, $lead, array_values($changes), $runtimes, $this->action($reason), $versionsOf($duePatches), $versionsOf($patches), $deadline))
            : $opening;

        $state = $isUpdate ? ':update:'.json_encode([array_keys($changes), $severity->value, $extended, $thread->last_notification_id]) : '';
        $dedupeKey = "pre_notice:incident:{$incident->id}:{$team->id}:".hash('sha256', $shown->map(fn (Advisory $notice): string => "{$notice->id}:{$notice->severity->value}")->sort()->join(',')."|{$versions}{$state}");

        $notification = $this->queueChatNotification->handle($team, ChatNotificationKind::PreNotice, $dedupeKey, $payload, attributes: [
            ...$thread->threadAttributes($role),
            'opening_payload' => $role === ChatThreadRole::Reply ? $opening : null,
        ]);

        if ($notification === null) {
            return false;
        }

        IncidentTeamNotice::insertOrIgnore($shown->map(fn (Advisory $notice): array => [
            'team_id' => $team->id,
            'advisory_id' => $notice->id,
            'severity' => $notice->severity->value,
            'reason' => $reason->value,
            'chat_notification_id' => $notification->id,
            'created_at' => now(),
        ])->all());

        foreach ($duePatches as $branch => $patch) {
            $this->coverPatchedFindings->handle(IncidentTeamPatch::create([
                'team_id' => $team->id,
                'advisory_id' => $patch['notice']->id,
                'technology' => $incident->technology,
                'branch' => $branch,
                'version' => $patch['version'],
                'severity' => $severity,
                'chat_notification_id' => $notification->id,
            ]), $covered[$branch]);
        }

        $thread->told_confirmed_branches = $incident->confirmedBranches($runtimes);

        if (isset($changes[IncidentAlertReason::Exploited->value])) {
            $thread->exploited_told_at ??= now();
        }

        $thread->recordQueued($notification);

        return true;
    }

    /**
     * E.g. "Hãng xác nhận lỗ hổng đang bị khai thác: “CVE-2026-1001 is being actively exploited in the wild.”".
     */
    private function exploitedLine(Advisory $notice): string
    {
        $excerpt = $notice->exploited_basis['excerpt'] ?? null;

        return blank($excerpt) ? __('Hãng xác nhận lỗ hổng đang bị khai thác.') : __('Hãng xác nhận lỗ hổng đang bị khai thác: “:excerpt”', ['excerpt' => $excerpt]);
    }

    /**
     * What a reply asks the team to do, for its most important change.
     */
    private function action(IncidentAlertReason $reason): string
    {
        return match ($reason) {
            IncidentAlertReason::PatchConfirmed => __('Nâng cấp các service dưới đây lên bản vá. Có bản vá không có nghĩa service đã an toàn: chỉ an toàn sau khi nâng cấp.'),
            IncidentAlertReason::Exploited => __('Lỗ hổng đang bị khai thác: ưu tiên nâng cấp hoặc giảm thiểu ngay.'),
            IncidentAlertReason::ScopeExtended => __('Kiểm tra và chuẩn bị nâng cấp các service ở nhánh bị ảnh hưởng.'),
            default => __('Xem lại mức ưu tiên xử lý theo mức độ mới.'),
        };
    }

    /**
     * The pre-notices that count now, oldest first.
     *
     * @param  Collection<int, Advisory>  $notices  Oldest first.
     * @return Collection<int, Advisory>
     */
    private function countingNotices(Collection $notices, bool $holdUnclassified): Collection
    {
        return $notices
            ->reject(fn (Advisory $notice): bool => $holdUnclassified && $notice->severity !== Severity::Critical && $notice->classified_by === null)
            ->values();
    }

    /**
     * Whether the incident is sent right away: one of its pre-notices is (see AlertPolicy::sendsPreNoticeRightAway()).
     *
     * @param  Collection<int, Advisory>  $notices
     */
    private function isUrgent(Collection $notices): bool
    {
        return $notices->contains(fn (Advisory $notice): bool => $this->policy->sendsPreNoticeRightAway($notice));
    }

    /**
     * The fixes the team has not had a "Đã có bản vá" card for: a branch it runs below the fixed version, with no card
     * from a pre-notice of the incident for that version or a later one. A runtime whose version is not known counts
     * as below.
     *
     * @param  Collection<int, Runtime>  $runtimes
     * @param  array<string, array{version: string, notice: Advisory}>  $patches
     * @param  list<int>  $memberIds
     * @return array<string, array{version: string, notice: Advisory}>
     */
    private function duePatches(int $teamId, Collection $runtimes, array $patches, array $memberIds): array
    {
        return collect($patches)
            ->filter(fn (array $patch, string $branch): bool => $runtimes->contains(fn (Runtime $runtime): bool => $runtime->branch === $branch && ($runtime->version === null || Version::isNewer($patch['version'], $runtime->version))))
            ->reject(fn (array $patch, string $branch): bool => IncidentTeamPatch::where('team_id', $teamId)
                ->where('branch', $branch)
                ->whereIn('advisory_id', $memberIds)
                ->pluck('version')
                ->contains(fn (string $version): bool => ! Version::isNewer($patch['version'], $version)))
            ->all();
    }
}

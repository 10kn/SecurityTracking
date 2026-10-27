<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Enums\FindingEventType;
use App\Enums\FindingState;
use App\Enums\IncidentScope;
use App\Enums\LockfileFormat;
use App\Enums\RuntimeStatus;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamNotice;
use App\Models\Lockfile;
use App\Models\Runtime;
use App\Models\RuntimeBranch;
use App\Models\RuntimeRelease;
use App\Models\Service;
use App\Models\Team;
use App\Services\AlertPolicy;
use App\Services\GoogleChatCards;
use App\Services\IncidentTeamExposure;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Localizable;

/**
 * The digest of handover 5.5, one card per team. It is sent only when there is something to read, so a team is not
 * reminded every day of what it already knows:
 *
 * - daily: findings opened since the previous digest, open findings at the immediate severities no admin marked as
 *   read (reminded every day until then), findings whose deadline passed since the previous digest (once, read or
 *   not), Medium and Low pre-notices, runtimes crossing an end-of-life reminder (`psm.digest.eol_reminder_days`) and
 *   lockfiles that became unreadable, incidents waiting for a fix when there is news about them (see
 *   awaitingPatch()), and incidents the team was told about at the immediate severities that no admin marked as read
 *   for it (reminded every day until then, like findings). Findings closed automatically, the other incidents waiting for a fix and the counts of the rest
 *   are shown when there is a digest anyway, but do not cause one.
 * - weekly, on `psm.digest.weekly_day` instead of the daily one: everything still open, findings closed in the last
 *   seven days, every runtime reaching end of life within `psm.digest.eol_warning_days`, every incident waiting for a
 *   fix, and the counts. A team with
 *   nothing at all gets none.
 *
 * Each digest records the end of the time window it reports on (`covers_until`, taken before reading anything); the
 * next one starts where the last delivered one ended, so a digest that failed loses nothing. Days are counted in
 * `psm.digest.timezone`.
 */
class SendDailyDigest
{
    use Localizable;

    public function __construct(
        private AlertPolicy $policy,
        private QueueChatNotification $queueChatNotification,
        private GoogleChatCards $cards,
        private AssignNoticeToIncident $assignNoticeToIncident,
    ) {}

    /**
     * @return int The number of digests queued. A team gets at most one per day.
     */
    public function handle(): int
    {
        $this->assignNoticeToIncident->assignUnassigned();

        $today = now(config('psm.digest.timezone'));
        $isWeekly = $today->dayOfWeek === config('psm.digest.weekly_day');

        return $this->withLocale(config('psm.alerts.locale'), fn (): int => Team::query()->orderBy('id')->get()->sum(function (Team $team) use ($today, $isWeekly): int {
            $coversUntil = now();
            $digest = $this->digestFor($team, $isWeekly);

            if ($isWeekly ? $this->isEmpty($digest) : ! $this->hasNews($digest)) {
                return 0;
            }

            $payload = $this->cards->digest($team, $digest, $today->translatedFormat(__('d/m/Y')));
            $dedupeKey = $isWeekly ? "digest-weekly:{$team->id}:{$today->format('o-\WW')}" : "digest:{$team->id}:{$today->toDateString()}";

            return $this->queueChatNotification->handle($team, ChatNotificationKind::Digest, $dedupeKey, $payload, attributes: ['covers_until' => $coversUntil]) === null ? 0 : 1;
        }));
    }

    /**
     * @return array{weekly: bool, new: Collection<int, Finding>, reminders: Collection<int, Finding>, overdue: Collection<int, Finding>, open: Collection<int, Finding>, resolved: Collection<int, Finding>, pre_notices: Collection<int, Advisory>, ending: Collection<int, array{runtime: Runtime, date: string}>, unknown: int, outdated: int, missing_lockfiles: int, unreadable_lockfiles: int, new_unreadable_lockfiles: int, awaiting_patch: Collection<int, array{incident: Incident, notice: Advisory, branches: list<string>, candidates: array<string, string>, needs_review: bool}>, awaiting_patch_news: bool, incident_reminders: Collection<int, array{incident: Incident, notice: Advisory, branches: list<string>, candidates: array<string, string>, needs_review: bool}>}
     */
    public function digestFor(Team $team, bool $isWeekly = false): array
    {
        $since = $this->windowStart($team);
        $resolvedSince = $isWeekly ? now()->subWeek() : $since;

        $findings = $team->findings()
            ->with('deployment.service.project')
            ->where(fn ($query) => $query->where('state', FindingState::Open)->orWhere('resolved_at', '>=', $resolvedSince))
            ->orderByRaw("case severity when 'critical' then 4 when 'high' then 3 when 'medium' then 2 when 'low' then 1 else 0 end desc")
            ->orderBy('title')
            ->get();

        $open = $findings->where('state', FindingState::Open);
        $newIds = $open->isEmpty() ? [] : Finding::whereKey($open->modelKeys())
            ->whereHas('events', fn ($query) => $query->where('type', FindingEventType::Opened)->where('created_at', '>=', $since))
            ->pluck('id')
            ->all();
        $notNew = $open->whereNotIn('id', $newIds);
        $reminders = $notNew->filter(fn (Finding $finding): bool => $this->policy->isImmediate($finding->severity) && ! $finding->isAcknowledged());
        $overdue = $notNew->diffKeys($reminders)->filter(fn (Finding $finding): bool => $finding->sla_due_at !== null && $finding->sla_due_at->between($since, now()));

        $runtimes = Runtime::with('deployment.service.project')
            ->whereHas('deployment', fn ($query) => $query->where('is_current', true)->whereHas('service.project', fn ($query) => $query->where('team_id', $team->id)))
            ->get();

        $services = Service::with('productionDeployment.lockfiles')
            ->whereHas('project', fn ($query) => $query->where('team_id', $team->id))
            ->get();
        $unreadable = $services->flatMap(function (Service $service): Collection {
            $lockfiles = $service->productionDeployment->lockfiles ?? collect();
            $superseded = LockfileFormat::supersededPaths($lockfiles->pluck('path'));

            return $lockfiles->filter(fn (Lockfile $lockfile): bool => $lockfile->isUnreadable() && ! isset($superseded[$lockfile->path]));
        });
        $awaitingPatch = $this->awaitingPatch($team, $runtimes, $since);

        return [
            'weekly' => $isWeekly,
            'new' => $open->whereIn('id', $newIds)->values(),
            'reminders' => $reminders->values(),
            'overdue' => $overdue->values(),
            'open' => $notNew->diffKeys($reminders)->diffKeys($overdue)->values(),
            'resolved' => $findings->where('state', FindingState::Resolved)->values(),
            'pre_notices' => $this->preNotices($team, $runtimes, $since),
            'ending' => $this->endingSoon($runtimes, $isWeekly ? null : $since),
            'unknown' => $runtimes->where('status', RuntimeStatus::Unknown)->count(),
            'outdated' => $runtimes->where('status', RuntimeStatus::Outdated)->count(),
            'missing_lockfiles' => $services->filter(fn (Service $service): bool => ($service->productionDeployment->lockfiles ?? collect())->isEmpty())->count(),
            'unreadable_lockfiles' => $unreadable->count(),
            'new_unreadable_lockfiles' => $unreadable->filter(fn (Lockfile $lockfile): bool => $lockfile->uploaded_at?->gte($since) ?? false)->count(),
            'awaiting_patch' => $awaitingPatch->where('is_reminder', false)->pluck('item')->values(),
            'awaiting_patch_news' => $awaitingPatch->where('is_reminder', false)->contains('is_news', true),
            'incident_reminders' => $awaitingPatch->where('is_reminder', true)->pluck('item')->values(),
        ];
    }

    /**
     * Where the last delivered digest ended. Before any was delivered, a day before the first one tried, so digests
     * failing from the start lose nothing either; a team that never had one starts a day back.
     */
    private function windowStart(Team $team): CarbonInterface
    {
        $digests = $team->chatNotifications()->where('kind', ChatNotificationKind::Digest);
        $delivered = (clone $digests)->whereNotNull('sent_at')->latest('id')->first();

        if ($delivered !== null) {
            return $delivered->covers_until ?? $delivered->created_at;
        }

        $first = $digests->oldest('id')->first();

        return ($first === null ? now() : ($first->covers_until ?? $first->created_at))->copy()->subDay();
    }

    /**
     * Incidents of a technology the team runs that were not sent right away (all their pre-notices are Medium or Low),
     * that are new or changed in severity or branches since the previous digest, and that the team got no card about.
     * Each is listed by its most severe pre-notice. An incident the classifier rates Medium only after the digest
     * started is in the next one.
     *
     * @param  Collection<int, Runtime>  $runtimes
     * @return Collection<int, Advisory>
     */
    private function preNotices(Team $team, Collection $runtimes, CarbonInterface $since): Collection
    {
        if ($runtimes->isEmpty()) {
            return collect();
        }

        return Advisory::alertable()
            ->whereIn('technology', $runtimes->pluck('technology.value')->unique())
            ->whereHas('incident', fn ($query) => $query->whereNull('merged_into_id')->where('changed_at', '>=', $since))
            ->orderBy('published_at')
            ->get()
            ->groupBy('incident_id')
            ->reject(fn (Collection $notices): bool => $notices->contains(fn (Advisory $notice): bool => $this->policy->sendsPreNoticeRightAway($notice)))
            ->reject(fn (Collection $notices, int $incidentId): bool => IncidentTeamNotice::severityToldTo($team->id, Advisory::where('incident_id', $incidentId)->pluck('id')->all()) !== null)
            ->map(fn (Collection $notices): Advisory => $notices->sortByDesc(fn (Advisory $notice): int => $notice->severity->rank())->first())
            ->sortBy('published_at')
            ->values();
    }

    /**
     * "Đang chờ bản vá": incidents the team got a card about in the last `psm.incidents.awaiting_patch_days`, with no
     * confirmed fix yet for a branch it runs that the vendor did not rule out. Security releases of those branches since
     * the incident began are listed as possible fixes; once `psm.incidents.review_after_days` pass, the incident asks
     * for a review. It never closes on its own. A card from before incidents counts too (see
     * IncidentTeamNotice::firstToldAt()).
     *
     * The list alone does not cause a daily digest, except for news since the previous one: an incident the team was
     * first told about, a new possible fix, or the review day.
     *
     * An admin's assessment for the team decides the rest, while it holds (see IncidentTeam::hasValidAssessment()): an
     * incident marked "Không" is left out, one marked "Có" or "Chưa rõ" stays listed. One the team was told about at the
     * immediate severities with no assessment that holds is a reminder instead (`is_reminder`), every day until an admin
     * marks it as read for the team. A runtime whose branch is not known counts as waiting for the reminder, listed by
     * its version, as no fix can be matched to it; it does not put an incident on the awaiting list by itself.
     *
     * @param  Collection<int, Runtime>  $runtimes
     * @return Collection<int, array{item: array{incident: Incident, notice: Advisory, branches: list<string>, candidates: array<string, string>, needs_review: bool}, is_news: bool, is_reminder: bool}>
     */
    private function awaitingPatch(Team $team, Collection $runtimes, CarbonInterface $since): Collection
    {
        if ($runtimes->isEmpty()) {
            return collect();
        }

        $reviewAfter = config('psm.incidents.review_after_days');

        return Incident::current()
            ->whereIn('technology', $runtimes->pluck('technology.value')->unique())
            ->where('first_published_at', '>=', now()->subDays(config('psm.incidents.awaiting_patch_days')))
            ->orderBy('first_published_at')
            ->get()
            ->map(function (Incident $incident) use ($team, $runtimes, $since, $reviewAfter): ?array {
                $all = $incident->notices()->get();
                $notices = $all->where('is_dismissed', false)->where('is_backfill', false);
                $firstTold = IncidentTeamNotice::firstToldAt($team->id, $all->modelKeys());

                if ($notices->isEmpty() || $firstTold === null) {
                    return null;
                }

                $patches = $incident->confirmedPatches($all);
                $technologyRuntimes = $runtimes->where('technology', $incident->technology)->values();
                $exposure = IncidentTeamExposure::of($incident, $all, $technologyRuntimes, $patches);
                $assessment = IncidentTeam::where('incident_id', $incident->id)->where('team_id', $team->id)->first();
                $isAssessed = $assessment?->hasValidAssessment($exposure) ?? false;

                if ($isAssessed && $assessment->isRuledOut($exposure)) {
                    return null;
                }

                $branches = $technologyRuntimes
                    ->filter(fn (Runtime $runtime): bool => $runtime->branch !== null && $incident->scopeFor($runtime) !== IncidentScope::NotAffected)
                    ->pluck('branch')
                    ->unique()
                    ->diff(array_keys($patches))
                    ->sort(SORT_NATURAL)
                    ->values()
                    ->all();
                $waiting = IncidentTeamExposure::waitingBranches($incident, $technologyRuntimes, $patches);
                $isReminder = $waiting !== [] && ! $isAssessed && $this->policy->remindsOfIncident(IncidentTeamNotice::severityToldTo($team->id, $all->modelKeys()));

                if ($branches === [] && ! $isReminder) {
                    return null;
                }

                $candidates = $incident->candidatePatches($branches);
                $reviewDay = $incident->first_published_at->copy()->addDays($reviewAfter);

                return [
                    'item' => [
                        'incident' => $incident,
                        'notice' => $notices->sortByDesc(fn (Advisory $notice): int => $notice->severity->rank())->first(),
                        'branches' => $isReminder ? $waiting : $branches,
                        'candidates' => array_map(fn (RuntimeRelease $release): string => $release->version, $candidates),
                        'needs_review' => ! $reviewDay->isFuture(),
                    ],
                    'is_news' => $firstTold->gte($since)
                        || collect($candidates)->contains(fn (RuntimeRelease $release): bool => $release->created_at->gte($since))
                        || $reviewDay->between($since, now()),
                    'is_reminder' => $isReminder,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Runtimes not yet end-of-life whose branch stops receiving security fixes within the warning window. With
     * `$since` (the daily digest), only those that crossed a reminder day since then, or appeared since then.
     *
     * @param  Collection<int, Runtime>  $runtimes
     * @return Collection<int, array{runtime: Runtime, date: string}>
     */
    private function endingSoon(Collection $runtimes, ?CarbonInterface $since): Collection
    {
        $candidates = $runtimes->whereNotIn('status', [RuntimeStatus::Eol, RuntimeStatus::Unknown])->whereNotNull('branch');

        if ($candidates->isEmpty()) {
            return collect();
        }

        $today = $this->businessDate(now());
        $branches = RuntimeBranch::whereIn('technology', $candidates->pluck('technology.value')->unique())
            ->whereDate('security_support_until', '>=', $today)
            ->whereDate('security_support_until', '<=', $today->copy()->addDays(config('psm.digest.eol_warning_days')))
            ->get()
            ->keyBy(fn (RuntimeBranch $branch): string => "{$branch->technology->value}:{$branch->branch}");

        return $candidates
            ->map(fn (Runtime $runtime): array => ['runtime' => $runtime, 'branch' => $branches->get("{$runtime->technology->value}:{$runtime->branch}")])
            ->filter(fn (array $item): bool => $item['branch'] !== null && ($since === null || $this->isDueReminder($item['runtime'], $item['branch'], $since)))
            ->map(fn (array $item): array => ['runtime' => $item['runtime'], 'date' => $item['branch']->security_support_until->translatedFormat(__('d/m/Y'))])
            ->values();
    }

    /**
     * A reminder day lies between the days left at the previous digest and the days left today, so a reminder missed
     * while the digest was not delivered is sent once on the next one. A runtime that appeared since is reminded too.
     */
    private function isDueReminder(Runtime $runtime, RuntimeBranch $branch, CarbonInterface $since): bool
    {
        if ($runtime->created_at->gte($since)) {
            return true;
        }

        $until = Carbon::parse($branch->security_support_until->toDateString());
        $daysLeft = (int) $this->businessDate(now())->diffInDays($until);
        $daysLeftThen = (int) $this->businessDate($since)->diffInDays($until);

        return collect(config('psm.digest.eol_reminder_days'))->contains(fn (int $day): bool => $daysLeft <= $day && $day < $daysLeftThen);
    }

    /**
     * The calendar day of a time in `psm.digest.timezone`, as a date at midnight, to compare with dates.
     */
    private function businessDate(CarbonInterface $time): Carbon
    {
        return Carbon::parse($time->copy()->setTimezone(config('psm.digest.timezone'))->toDateString());
    }

    /**
     * Whether the daily digest has something to read. Findings closed automatically, new findings an admin already
     * read and counts that did not change wait for the weekly one.
     *
     * @param  array{weekly: bool, new: Collection<int, Finding>, reminders: Collection<int, Finding>, overdue: Collection<int, Finding>, open: Collection<int, Finding>, resolved: Collection<int, Finding>, pre_notices: Collection<int, Advisory>, ending: Collection<int, array{runtime: Runtime, date: string}>, unknown: int, outdated: int, missing_lockfiles: int, unreadable_lockfiles: int, new_unreadable_lockfiles: int, awaiting_patch: Collection<int, array{incident: Incident, notice: Advisory, branches: list<string>, candidates: array<string, string>, needs_review: bool}>, awaiting_patch_news: bool, incident_reminders: Collection<int, array{incident: Incident, notice: Advisory, branches: list<string>, candidates: array<string, string>, needs_review: bool}>}  $digest
     */
    private function hasNews(array $digest): bool
    {
        return $digest['new']->contains(fn (Finding $finding): bool => ! $finding->isAcknowledged())
            || $digest['reminders']->isNotEmpty() || $digest['overdue']->isNotEmpty() || $digest['pre_notices']->isNotEmpty()
            || $digest['ending']->isNotEmpty() || $digest['new_unreadable_lockfiles'] > 0 || $digest['awaiting_patch_news'] || $digest['incident_reminders']->isNotEmpty();
    }

    /**
     * Services without a lockfile are counted when there is a digest anyway, but do not cause one on their own: some
     * services have no package manager the MVP reads.
     *
     * @param  array{weekly: bool, new: Collection<int, Finding>, reminders: Collection<int, Finding>, overdue: Collection<int, Finding>, open: Collection<int, Finding>, resolved: Collection<int, Finding>, pre_notices: Collection<int, Advisory>, ending: Collection<int, array{runtime: Runtime, date: string}>, unknown: int, outdated: int, missing_lockfiles: int, unreadable_lockfiles: int, new_unreadable_lockfiles: int, awaiting_patch: Collection<int, array{incident: Incident, notice: Advisory, branches: list<string>, candidates: array<string, string>, needs_review: bool}>, awaiting_patch_news: bool, incident_reminders: Collection<int, array{incident: Incident, notice: Advisory, branches: list<string>, candidates: array<string, string>, needs_review: bool}>}  $digest
     */
    private function isEmpty(array $digest): bool
    {
        return ! $this->hasNews($digest) && $digest['new']->isEmpty() && $digest['open']->isEmpty() && $digest['resolved']->isEmpty() && $digest['awaiting_patch']->isEmpty() && $digest['incident_reminders']->isEmpty()
            && $digest['unknown'] === 0 && $digest['outdated'] === 0 && $digest['unreadable_lockfiles'] === 0;
    }
}

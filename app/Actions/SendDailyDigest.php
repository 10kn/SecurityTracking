<?php

namespace App\Actions;

use App\Enums\AdvisoryKind;
use App\Enums\ChatNotificationKind;
use App\Enums\FindingEventType;
use App\Enums\FindingState;
use App\Enums\RuntimeStatus;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\Lockfile;
use App\Models\Runtime;
use App\Models\RuntimeBranch;
use App\Models\Service;
use App\Models\Team;
use App\Services\AlertPolicy;
use App\Services\GoogleChatCards;
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
 *   lockfiles that became unreadable. Findings closed automatically and the counts of the rest are shown when there
 *   is a digest anyway, but do not cause one.
 * - weekly, on `psm.digest.weekly_day` instead of the daily one: everything still open, findings closed in the last
 *   seven days, every runtime reaching end of life within `psm.digest.eol_warning_days`, and the counts. A team with
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
    ) {}

    /**
     * @return int The number of digests queued. A team gets at most one per day.
     */
    public function handle(): int
    {
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
     * @return array{weekly: bool, new: Collection<int, Finding>, reminders: Collection<int, Finding>, overdue: Collection<int, Finding>, open: Collection<int, Finding>, resolved: Collection<int, Finding>, pre_notices: Collection<int, Advisory>, ending: Collection<int, array{runtime: Runtime, date: string}>, unknown: int, outdated: int, missing_lockfiles: int, unreadable_lockfiles: int, new_unreadable_lockfiles: int}
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
        $unreadable = $services->flatMap(fn (Service $service) => ($service->productionDeployment->lockfiles ?? collect())->filter->isUnreadable());

        return [
            'weekly' => $isWeekly,
            'new' => $open->whereIn('id', $newIds)->values(),
            'reminders' => $reminders->values(),
            'overdue' => $overdue->values(),
            'open' => $notNew->diffKeys($reminders)->diffKeys($overdue)->values(),
            'resolved' => $findings->where('state', FindingState::Resolved)->values(),
            'pre_notices' => $this->preNotices($runtimes, $since),
            'ending' => $this->endingSoon($runtimes, $isWeekly ? null : $since),
            'unknown' => $runtimes->where('status', RuntimeStatus::Unknown)->count(),
            'outdated' => $runtimes->where('status', RuntimeStatus::Outdated)->count(),
            'missing_lockfiles' => $services->filter(fn (Service $service): bool => ($service->productionDeployment->lockfiles ?? collect())->isEmpty())->count(),
            'unreadable_lockfiles' => $unreadable->count(),
            'new_unreadable_lockfiles' => $unreadable->filter(fn (Lockfile $lockfile): bool => $lockfile->uploaded_at?->gte($since) ?? false)->count(),
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
     * Pre-notices stored or changed since the previous digest that were not sent right away (Medium and Low), of a
     * technology the team runs. Changes count, so an entry the classifier rates Medium only after the digest started
     * is in the next one.
     *
     * @param  Collection<int, Runtime>  $runtimes
     * @return Collection<int, Advisory>
     */
    private function preNotices(Collection $runtimes, CarbonInterface $since): Collection
    {
        if ($runtimes->isEmpty()) {
            return collect();
        }

        return Advisory::where('kind', AdvisoryKind::PreNotice)
            ->whereIn('technology', $runtimes->pluck('technology.value')->unique())
            ->where('is_dismissed', false)
            ->where('is_backfill', false)
            ->where('published_at', '>=', now()->subDays(config('psm.notices.relevant_days')))
            ->where('updated_at', '>=', $since)
            ->orderBy('published_at')
            ->get()
            ->reject(fn (Advisory $advisory): bool => $this->policy->sendsPreNoticeRightAway($advisory))
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
     * @param  array{weekly: bool, new: Collection<int, Finding>, reminders: Collection<int, Finding>, overdue: Collection<int, Finding>, open: Collection<int, Finding>, resolved: Collection<int, Finding>, pre_notices: Collection<int, Advisory>, ending: Collection<int, array{runtime: Runtime, date: string}>, unknown: int, outdated: int, missing_lockfiles: int, unreadable_lockfiles: int, new_unreadable_lockfiles: int}  $digest
     */
    private function hasNews(array $digest): bool
    {
        return $digest['new']->contains(fn (Finding $finding): bool => ! $finding->isAcknowledged())
            || $digest['reminders']->isNotEmpty() || $digest['overdue']->isNotEmpty() || $digest['pre_notices']->isNotEmpty()
            || $digest['ending']->isNotEmpty() || $digest['new_unreadable_lockfiles'] > 0;
    }

    /**
     * Services without a lockfile are counted when there is a digest anyway, but do not cause one on their own: some
     * services have no package manager the MVP reads.
     *
     * @param  array{weekly: bool, new: Collection<int, Finding>, reminders: Collection<int, Finding>, overdue: Collection<int, Finding>, open: Collection<int, Finding>, resolved: Collection<int, Finding>, pre_notices: Collection<int, Advisory>, ending: Collection<int, array{runtime: Runtime, date: string}>, unknown: int, outdated: int, missing_lockfiles: int, unreadable_lockfiles: int, new_unreadable_lockfiles: int}  $digest
     */
    private function isEmpty(array $digest): bool
    {
        return ! $this->hasNews($digest) && $digest['new']->isEmpty() && $digest['open']->isEmpty() && $digest['resolved']->isEmpty()
            && $digest['unknown'] === 0 && $digest['outdated'] === 0 && $digest['unreadable_lockfiles'] === 0;
    }
}

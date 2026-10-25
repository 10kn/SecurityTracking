<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Enums\FindingEventType;
use App\Enums\FindingState;
use App\Enums\RuntimeStatus;
use App\Models\Finding;
use App\Models\Runtime;
use App\Models\RuntimeBranch;
use App\Models\Service;
use App\Models\Team;
use App\Services\GoogleChatCards;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Localizable;

/**
 * The daily digest of handover 5.5, one card per team: findings opened since the previous digest, findings still
 * open, findings closed automatically, runtimes with missing data, a newer release or an end-of-life date within
 * `psm.digest.eol_warning_days`, and services whose packages are unknown (no lockfile, or one that cannot be read).
 * It is also where findings below the immediate severities show up.
 */
class SendDailyDigest
{
    use Localizable;

    public function __construct(private QueueChatNotification $queueChatNotification, private GoogleChatCards $cards) {}

    /**
     * @return int The number of digests queued. A team with nothing to report gets none, and a team gets one per day.
     */
    public function handle(): int
    {
        $today = now(config('psm.digest.timezone'));

        return $this->withLocale(config('psm.alerts.locale'), fn (): int => Team::query()->orderBy('id')->get()->sum(function (Team $team) use ($today): int {
            $digest = $this->digestFor($team);

            if ($this->isEmpty($digest)) {
                return 0;
            }

            $payload = $this->cards->digest($team, $digest, $today->translatedFormat(__('d/m/Y')));

            return $this->queueChatNotification->handle($team, ChatNotificationKind::Digest, "digest:{$team->id}:{$today->toDateString()}", $payload) === null ? 0 : 1;
        }));
    }

    /**
     * @return array{new: Collection<int, Finding>, open: Collection<int, Finding>, resolved: Collection<int, Finding>, unknown: int, outdated: int, ending: Collection<int, array{runtime: Runtime, date: string}>, missing_lockfiles: int, unreadable_lockfiles: int}
     */
    public function digestFor(Team $team): array
    {
        $since = $team->chatNotifications()->where('kind', ChatNotificationKind::Digest)->latest('id')->value('created_at');
        $since = $since === null ? now()->subDay() : Carbon::parse($since);

        $findings = $team->findings()
            ->with('deployment.service.project')
            ->where(fn ($query) => $query->where('state', FindingState::Open)->orWhere('resolved_at', '>=', $since))
            ->orderByRaw("case severity when 'critical' then 4 when 'high' then 3 when 'medium' then 2 when 'low' then 1 else 0 end desc")
            ->orderBy('title')
            ->get();

        $open = $findings->where('state', FindingState::Open);
        $newIds = $open->isEmpty() ? [] : Finding::whereKey($open->modelKeys())
            ->whereHas('events', fn ($query) => $query->where('type', FindingEventType::Opened)->where('created_at', '>=', $since))
            ->pluck('id')
            ->all();

        $runtimes = Runtime::with('deployment.service.project')
            ->whereHas('deployment', fn ($query) => $query->where('is_current', true)->whereHas('service.project', fn ($query) => $query->where('team_id', $team->id)))
            ->get();

        $services = Service::with('productionDeployment.lockfiles')
            ->whereHas('project', fn ($query) => $query->where('team_id', $team->id))
            ->get();

        return [
            'new' => $open->whereIn('id', $newIds)->values(),
            'open' => $open->whereNotIn('id', $newIds)->values(),
            'resolved' => $findings->where('state', FindingState::Resolved)->values(),
            'unknown' => $runtimes->where('status', RuntimeStatus::Unknown)->count(),
            'outdated' => $runtimes->where('status', RuntimeStatus::Outdated)->count(),
            'ending' => $this->endingSoon($runtimes),
            'missing_lockfiles' => $services->filter(fn (Service $service): bool => ($service->productionDeployment->lockfiles ?? collect())->isEmpty())->count(),
            'unreadable_lockfiles' => $services->sum(fn (Service $service): int => ($service->productionDeployment->lockfiles ?? collect())->filter->isUnreadable()->count()),
        ];
    }

    /**
     * Runtimes not yet end-of-life whose branch stops receiving security fixes within the warning window.
     *
     * @param  Collection<int, Runtime>  $runtimes
     * @return Collection<int, array{runtime: Runtime, date: string}>
     */
    private function endingSoon(Collection $runtimes): Collection
    {
        $candidates = $runtimes->whereNotIn('status', [RuntimeStatus::Eol, RuntimeStatus::Unknown])->whereNotNull('branch');

        if ($candidates->isEmpty()) {
            return collect();
        }

        $branches = RuntimeBranch::whereIn('technology', $candidates->pluck('technology.value')->unique())
            ->whereDate('security_support_until', '>=', today())
            ->whereDate('security_support_until', '<=', today()->addDays(config('psm.digest.eol_warning_days')))
            ->get()
            ->keyBy(fn (RuntimeBranch $branch): string => "{$branch->technology->value}:{$branch->branch}");

        return $candidates
            ->filter(fn (Runtime $runtime): bool => $branches->has("{$runtime->technology->value}:{$runtime->branch}"))
            ->map(fn (Runtime $runtime): array => [
                'runtime' => $runtime,
                'date' => $branches->get("{$runtime->technology->value}:{$runtime->branch}")->security_support_until->translatedFormat(__('d/m/Y')),
            ])
            ->values();
    }

    /**
     * Services without a lockfile are counted when there is a digest anyway, but do not cause one on their own: some
     * services have no package manager the MVP reads.
     *
     * @param  array{new: Collection<int, Finding>, open: Collection<int, Finding>, resolved: Collection<int, Finding>, unknown: int, outdated: int, ending: Collection<int, array{runtime: Runtime, date: string}>, missing_lockfiles: int, unreadable_lockfiles: int}  $digest
     */
    private function isEmpty(array $digest): bool
    {
        return $digest['new']->isEmpty() && $digest['open']->isEmpty() && $digest['resolved']->isEmpty()
            && $digest['unknown'] === 0 && $digest['outdated'] === 0 && $digest['ending']->isEmpty()
            && $digest['unreadable_lockfiles'] === 0;
    }
}

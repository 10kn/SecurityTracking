<?php

namespace App\Actions;

use App\Enums\FindingEventType;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An admin marks findings as read ("Đã đọc"): their daily reminder stops, they stay open until fixed, and they are
 * reminded once more when their deadline passes. Only the findings the admin was shown are marked, and only those
 * still open and not read yet.
 *
 * Each finding comes with the version the admin saw: the id of its latest opening, rise in severity or CISA KEV listing
 * one of its CVE ids. A finding that opened again, rose or was newly listed since is left unread, so a page left open
 * never confirms what the admin has not seen.
 */
class AcknowledgeFindings
{
    /**
     * The event types that change what a finding asks of the team.
     *
     * @var list<FindingEventType>
     */
    public const array VERSION_EVENTS = [FindingEventType::Opened, FindingEventType::Escalated, FindingEventType::KnownExploited];

    /**
     * @param  array<int, int|null>  $versions  Finding id => the version the admin saw.
     * @return array{marked: int, changed: int}
     */
    public function handle(User $user, array $versions): array
    {
        return DB::transaction(function () use ($user, $versions): array {
            $findings = Finding::open()
                ->whereKey(array_keys($versions))
                ->whereNull('acknowledged_at')
                ->lockForUpdate()
                ->get();

            $current = FindingEvent::whereIn('finding_id', $findings->modelKeys())
                ->whereIn('type', self::VERSION_EVENTS)
                ->groupBy('finding_id')
                ->selectRaw('finding_id, max(id) as version')
                ->pluck('version', 'finding_id');

            [$unchanged, $changed] = $findings->partition(fn (Finding $finding): bool => (int) ($current[$finding->id] ?? 0) === (int) ($versions[$finding->id] ?? 0));

            $unchanged->each(function (Finding $finding) use ($user): void {
                $finding->update(['acknowledged_at' => now(), 'acknowledged_by' => $user->id]);
                $finding->events()->create(['type' => FindingEventType::Acknowledged, 'data' => ['user_id' => $user->id, 'user' => $user->name]]);
            });

            return ['marked' => $unchanged->count(), 'changed' => $changed->count()];
        });
    }
}

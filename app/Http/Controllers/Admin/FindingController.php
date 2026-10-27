<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AcknowledgeFindings;
use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\FindingResource;
use App\Models\Finding;
use App\Models\Team;
use App\Support\FindingSearch;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The open findings, where an admin marks them as read ("Đã đọc") to stop their daily reminder. The links in the
 * Google Chat cards open it filtered on one team, and on the findings of one card (`ids`) for an immediate alert.
 */
class FindingController extends Controller
{
    /**
     * @var list<string>
     */
    private const array STATUSES = ['unread', 'read', 'overdue'];

    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest($request, ['severity', 'title', 'sla_due_at', 'first_seen_at'], 'severity');
        $teamId = $request->integer('team') ?: null;
        $status = in_array($request->string('status')->toString(), self::STATUSES, true) ? $request->string('status')->toString() : null;
        $ids = collect(explode(',', $request->string('ids')->toString()))->filter(fn (string $id): bool => ctype_digit($id))->map(fn (string $id): int => (int) $id)->take(200)->values()->all();

        $findings = FindingSearch::apply(Finding::open(), $table->search)
            ->with(['deployment.service.project', 'team', 'acknowledgedBy'])
            ->withMax(['events as version' => fn (Builder $query) => $query->whereIn('type', AcknowledgeFindings::VERSION_EVENTS)], 'id')
            ->when($teamId !== null, fn (Builder $query) => $query->where('team_id', $teamId))
            ->when($ids !== [], fn (Builder $query) => $query->whereKey($ids))
            ->when($status === 'unread', fn (Builder $query) => $query->whereNull('acknowledged_at'))
            ->when($status === 'read', fn (Builder $query) => $query->whereNotNull('acknowledged_at'))
            ->when($status === 'overdue', fn (Builder $query) => $query->where('sla_due_at', '<', now()))
            ->tap(fn (Builder $query) => match ($table->sort) {
                'severity' => $query->orderByRaw($this->severityOrder().' '.($table->descending ? 'asc' : 'desc')),
                'title' => FindingSearch::orderByTitle($query, $table->descending),
                default => $query->orderBy($table->sort, $table->direction()),
            })
            ->orderBy('sla_due_at')
            ->orderBy('id')
            ->paginate($table->perPage)
            ->withQueryString();

        return Inertia::render('findings/index', [
            'findings' => FindingResource::collection($findings),
            'table' => [...$table->toArray(), 'team' => $teamId, 'status' => $status, 'ids' => $ids === [] ? null : implode(',', $ids)],
            'teams' => Team::orderBy('name')->get()->map(fn (Team $team): array => ['value' => $team->id, 'label' => $team->name])->all(),
            'statuses' => [
                ['value' => 'unread', 'label' => __('Chưa đọc')],
                ['value' => 'read', 'label' => __('Đã đọc')],
                ['value' => 'overdue', 'label' => __('Quá hạn')],
            ],
        ]);
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

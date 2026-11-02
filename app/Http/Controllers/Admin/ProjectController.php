<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FindingState;
use App\Enums\ProjectEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectRequest;
use App\Http\Resources\Admin\ProjectResource;
use App\Http\Resources\Admin\ServiceResource;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\Team;
use App\Support\LocalTime;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest($request, ['name', 'team', 'services_count'], 'name');
        $teamId = $request->integer('team') ?: null;

        $projects = $table->search(Project::query(), ['name', 'slug'])
            ->with('team')
            ->withCount('services')
            ->when($teamId !== null, fn (Builder $query) => $query->where('team_id', $teamId))
            ->when(
                $table->sort === 'team',
                fn (Builder $query) => $query->orderBy(Team::select('name')->whereColumn('teams.id', 'projects.team_id'), $table->direction()),
                fn (Builder $query) => $query->orderBy($table->sort, $table->direction()),
            )
            ->orderBy('id')
            ->paginate($table->perPage)
            ->withQueryString();

        return Inertia::render('projects/index', [
            'projects' => ProjectResource::collection($projects),
            'table' => [...$table->toArray(), 'team' => $teamId],
            'teams' => $this->teamOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('projects/create', [
            'teams' => $this->teamOptions(),
            'defaultTeamId' => $request->integer('team') ?: null,
        ]);
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = Project::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã tạo project :project.', ['project' => $project->name])]);

        return to_route('admin.projects.show', $project);
    }

    public function show(Project $project): Response
    {
        $project->load(['team.latestChatNotification', 'pausedBy', 'events' => fn ($query) => $query->with('user')->latest('id')->limit(10)]);

        $services = $project->services()
            ->with(ServiceResource::EAGER_LOADS)
            ->withCount(['findings as open_findings_count' => fn (Builder $query) => $query->where('state', FindingState::Open)])
            ->orderBy('name')
            ->get();

        $chatStatus = $project->team->googleChatStatus();

        return Inertia::render('projects/show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'team' => ['id' => $project->team->id, 'name' => $project->team->name],
                'alert_channel' => ['label' => __('Google Chat của team').' · '.$chatStatus->getLabel(), 'tone' => $chatStatus->dotTone()],
                'created_at' => LocalTime::date($project->created_at),
                'monitoring' => $project->isPaused() ? [
                    'paused_at' => LocalTime::format($project->monitoring_paused_at),
                    'by' => $project->pausedBy?->name,
                    'reason' => $project->monitoring_pause_reason,
                ] : null,
            ],
            'history' => $project->events
                ->map(fn (ProjectEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type->getLabel(),
                    'detail' => match ($event->type) {
                        ProjectEventType::Paused => (string) ($event->data['reason'] ?? ''),
                        ProjectEventType::Resumed => collect([($event->data['notify'] ?? false) ? __('Gửi tình trạng hiện tại cho team') : __('Không gửi, đánh dấu tồn đọng đã đọc'), $event->data['note'] ?? null])->filter()->join(' · '),
                    },
                    'by' => $event->user?->name ?? $event->data['user'] ?? null,
                    'at' => LocalTime::format($event->created_at),
                ])
                ->values()
                ->all(),
            'services' => ServiceResource::collection($services),
        ]);
    }

    public function edit(Project $project): Response
    {
        return Inertia::render('projects/edit', [
            'project' => $project->only(['id', 'team_id', 'name', 'slug']),
            'teams' => $this->teamOptions(),
        ]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã lưu project :project.', ['project' => $project->name])]);

        return to_route('admin.projects.show', $project);
    }

    /**
     * Deleting a project also deletes its services, their runtime versions and lockfiles (cascade).
     */
    public function destroy(Project $project): RedirectResponse
    {
        $project->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã xóa project :project.', ['project' => $project->name])]);

        return to_route('admin.projects.index');
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function teamOptions(): array
    {
        return Team::orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Team $team): array => ['value' => $team->id, 'label' => $team->name])
            ->all();
    }
}

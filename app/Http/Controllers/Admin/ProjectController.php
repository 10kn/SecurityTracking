<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FindingState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectRequest;
use App\Http\Resources\Admin\ProjectResource;
use App\Http\Resources\Admin\ServiceResource;
use App\Jobs\SyncProjectLockfilesJob;
use App\Models\Project;
use App\Models\Team;
use App\Services\Repositories\GitHubRepository;
use App\Services\Repositories\RepositoryReader;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(private RepositoryReader $repositoryReader) {}

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
            'gitHubConfigured' => $this->repositoryReader->isConfigured(),
        ]);
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = Project::create($request->validated());

        $this->syncLockfilesWhenSettingsChanged($project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã tạo project :project.', ['project' => $project->name])]);

        return to_route('admin.projects.show', $project);
    }

    public function show(Project $project): Response
    {
        $project->load('team.latestChatNotification');

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
                'repository_url' => $project->repository_url,
                'repository_href' => str_starts_with((string) $project->repository_url, 'https://') ? $project->repository_url : null,
                'repository_branch' => $project->repository_branch,
                'lockfile_sync' => $this->lockfileSync($project),
                'team' => ['id' => $project->team->id, 'name' => $project->team->name],
                'alert_channel' => ['label' => __('Google Chat của team').' · '.$chatStatus->getLabel(), 'tone' => $chatStatus->dotTone()],
                'created_at' => $project->created_at?->translatedFormat(__('d/m/Y')),
            ],
            'services' => ServiceResource::collection($services),
        ]);
    }

    public function edit(Project $project): Response
    {
        return Inertia::render('projects/edit', [
            'project' => $project->only(['id', 'team_id', 'name', 'slug', 'repository_url', 'repository_branch', 'syncs_lockfiles']),
            'teams' => $this->teamOptions(),
            'gitHubConfigured' => $this->repositoryReader->isConfigured(),
        ]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        $this->syncLockfilesWhenSettingsChanged($project);

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
     * Reads the lockfiles right away when syncing was turned on or the repository or branch changed, instead of
     * waiting for the next daily sync.
     */
    private function syncLockfilesWhenSettingsChanged(Project $project): void
    {
        if ($project->syncs_lockfiles && ($project->wasRecentlyCreated || $project->wasChanged(['repository_url', 'repository_branch', 'syncs_lockfiles']))) {
            SyncProjectLockfilesJob::dispatch($project);
        }
    }

    /**
     * The state of reading lockfiles from the repository, or null when the project does not.
     *
     * @return array{status: array{label: string, tone: string}, synced_at: string|null, commit: string|null, commit_href: string|null, error: string|null}|null
     */
    private function lockfileSync(Project $project): ?array
    {
        if (! $project->syncs_lockfiles) {
            return null;
        }

        $repository = GitHubRepository::fromUrl((string) $project->repository_url);
        $commitSha = $project->lockfiles_commit_sha;

        return [
            'status' => match (true) {
                $project->lockfiles_sync_error !== null => ['label' => __('Lần đồng bộ gần nhất lỗi'), 'tone' => 'warning'],
                $project->lockfiles_synced_at === null => ['label' => __('Chưa đồng bộ'), 'tone' => 'unknown'],
                default => ['label' => __('Đã đồng bộ'), 'tone' => 'success'],
            },
            'synced_at' => $project->lockfiles_synced_at?->translatedFormat(__('d/m/Y H:i')),
            'commit' => $commitSha === null ? null : substr($commitSha, 0, 7),
            'commit_href' => $commitSha === null || $repository === null ? null : $repository->webUrl()."/commit/{$commitSha}",
            'error' => $project->lockfiles_sync_error,
        ];
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

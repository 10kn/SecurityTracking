<?php

namespace App\Http\Controllers\Admin;

use App\Actions\UpdateProductionInventory;
use App\Enums\Exposure;
use App\Enums\FindingState;
use App\Enums\ServiceKind;
use App\Enums\Technology;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceRequest;
use App\Http\Resources\Admin\ServiceResource;
use App\Jobs\SyncServiceLockfilesJob;
use App\Models\AccessibleRepository;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Services\Repositories\RepositoryReader;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function __construct(
        private UpdateProductionInventory $updateProductionInventory,
        private RepositoryReader $repositoryReader,
    ) {}

    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest($request, ['name', 'project', 'open_findings_count'], 'name');
        $projectId = $request->integer('project') ?: null;
        $isAttention = $request->boolean('attention');

        $services = $table->search(Service::query(), ['name', 'slug'])
            ->with(['project.team', ...ServiceResource::EAGER_LOADS])
            ->withCount(['findings as open_findings_count' => fn (Builder $query) => $query->where('state', FindingState::Open)])
            ->when($projectId !== null, fn (Builder $query) => $query->where('project_id', $projectId))
            ->when($isAttention, fn (Builder $query) => $query->notFullyEvaluated())
            ->when(
                $table->sort === 'project',
                fn (Builder $query) => $query->orderBy(Project::select('name')->whereColumn('projects.id', 'services.project_id'), $table->direction()),
                fn (Builder $query) => $query->orderBy($table->sort, $table->direction()),
            )
            ->orderBy('id')
            ->paginate($table->perPage)
            ->withQueryString();

        return Inertia::render('services/index', [
            'services' => ServiceResource::collection($services),
            'table' => [...$table->toArray(), 'project' => $projectId, 'attention' => $isAttention ? 1 : null],
            'projects' => $this->projectOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('services/create', [
            ...$this->formOptions(),
            'defaultProjectId' => $request->integer('project') ?: null,
        ]);
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        $service = DB::transaction(function () use ($request): Service {
            $service = Service::create($request->serviceAttributes());

            $this->updateProductionInventory->handle($service, $request->runtimeVersions(), $request->uploadedLockfiles());

            return $service;
        });

        $this->syncLockfilesWhenRepositoryChanged($service);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã tạo service :service. Hệ thống đang đánh giá lại.', ['service' => $service->name])]);

        return to_route('admin.services.edit', $service);
    }

    public function edit(Service $service): Response
    {
        $service->load(['project.team', 'productionDeployment.runtimes', 'productionDeployment.lockfiles']);
        $deployment = $service->productionDeployment;

        return Inertia::render('services/edit', [
            ...$this->formOptions(),
            'service' => [
                ...$service->only(['id', 'project_id', 'name', 'slug', 'repository_path', 'repository', 'repository_branch']),
                'kind' => $service->kind->value,
                'exposure' => $service->exposure->value,
                'project' => ['id' => $service->project->id, 'name' => $service->project->name, 'team' => $service->project->team->name],
                'runtimes' => ($deployment->runtimes ?? collect())
                    ->map(fn (Runtime $runtime): array => ['technology' => $runtime->technology->value, 'raw_version' => $runtime->raw_version])
                    ->values()
                    ->all(),
                'lockfiles' => ($deployment->lockfiles ?? collect())
                    ->sortBy('path')
                    ->map(fn (Lockfile $lockfile): array => [
                        'path' => $lockfile->path,
                        'source' => $lockfile->source->value,
                        'commit' => $lockfile->commit_sha === null ? null : substr($lockfile->commit_sha, 0, 7),
                        'uploaded_at' => $lockfile->uploaded_at->translatedFormat(__('d/m/Y H:i')),
                    ])
                    ->values()
                    ->all(),
                'lockfile_excludes' => implode("\n", $service->lockfile_excludes ?? []),
                'lockfile_sync' => $this->lockfileSync($service),
            ],
        ]);
    }

    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        DB::transaction(function () use ($request, $service): void {
            $service->fill($request->serviceAttributes());

            // The state of the last sync belongs to the repository and branch it read.
            if ($service->isDirty(['repository', 'repository_branch'])) {
                $service->fill(['lockfiles_synced_at' => null, 'lockfiles_commit_sha' => null, 'lockfiles_sync_error' => null]);
            }

            $service->save();

            $this->updateProductionInventory->handle(
                $service,
                $request->runtimeVersions(),
                $request->uploadedLockfiles(),
                $request->removedLockfilePaths(),
            );
        });

        $this->syncLockfilesWhenRepositoryChanged($service);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã lưu service :service. Hệ thống đang đánh giá lại.', ['service' => $service->name])]);

        return to_route('admin.services.edit', $service);
    }

    /**
     * Deleting a service also deletes its runtime versions, lockfiles and findings (cascade).
     */
    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã xóa service :service.', ['service' => $service->name])]);

        return to_route('admin.projects.show', $service->project_id);
    }

    /**
     * A service that reads its lockfiles from GitHub gets them right away when it is created or its repository, branch,
     * directory or lockfile exclusions changed, instead of at the next daily sync.
     */
    private function syncLockfilesWhenRepositoryChanged(Service $service): void
    {
        if ($service->syncsLockfiles() && ($service->wasRecentlyCreated || $service->wasChanged(['repository', 'repository_branch', 'repository_path', 'lockfile_excludes']))) {
            SyncServiceLockfilesJob::dispatch($service);
        }
    }

    /**
     * The state of reading lockfiles from the repository, or null when the service does not.
     *
     * @return array{status: array{label: string, tone: string}, synced_at: string|null, commit: string|null, commit_href: string|null, error: string|null}|null
     */
    private function lockfileSync(Service $service): ?array
    {
        if (! $service->syncsLockfiles()) {
            return null;
        }

        $commitSha = $service->lockfiles_commit_sha;

        return [
            'status' => match (true) {
                $service->lockfiles_sync_error !== null => ['label' => __('Lần đồng bộ gần nhất lỗi'), 'tone' => 'warning'],
                $service->lockfiles_synced_at === null => ['label' => __('Chưa đồng bộ'), 'tone' => 'unknown'],
                default => ['label' => __('Đã đồng bộ'), 'tone' => 'success'],
            },
            'synced_at' => $service->lockfiles_synced_at?->translatedFormat(__('d/m/Y H:i')),
            'commit' => $commitSha === null ? null : substr($commitSha, 0, 7),
            'commit_href' => $commitSha === null ? null : $this->repositoryReader->commitUrl((string) $service->repository, $commitSha),
            'error' => $service->lockfiles_sync_error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'projects' => $this->projectOptions(),
            'repositories' => $this->repositoryOptions(),
            'repositoriesSyncedAt' => AccessibleRepository::latest('updated_at')->first()?->updated_at?->translatedFormat(__('d/m/Y H:i')),
            'gitHubConfigured' => $this->repositoryReader->isConfigured(),
            'lockfileSkippedDirectories' => config('psm.github.lockfile_search.skip_directories'),
            'kinds' => array_map(fn (ServiceKind $kind): array => ['value' => $kind->value, 'label' => $kind->getLabel()], ServiceKind::cases()),
            'exposures' => array_map(fn (Exposure $exposure): array => ['value' => $exposure->value, 'label' => $exposure->getLabel()], Exposure::cases()),
            'technologies' => collect(Technology::cases())
                ->sortBy(fn (Technology $technology): string => $technology->getLabel())
                ->map(fn (Technology $technology): array => [
                    'value' => $technology->value,
                    'label' => $technology->getLabel(),
                    'versions' => $technology->versionSuggestions(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The repositories the GitHub App may read, as last refreshed.
     *
     * @return list<array{value: string, label: string, description: string, default_branch: string|null}>
     */
    private function repositoryOptions(): array
    {
        return AccessibleRepository::orderBy('full_name')
            ->get()
            ->map(fn (AccessibleRepository $repository): array => [
                'value' => $repository->full_name,
                'label' => $repository->full_name,
                'description' => implode(' · ', array_filter([
                    $repository->default_branch,
                    $repository->is_private ? __('private') : __('public'),
                ])),
                'default_branch' => $repository->default_branch,
            ])
            ->all();
    }

    /**
     * @return list<array{value: int, label: string, description: string}>
     */
    private function projectOptions(): array
    {
        return Project::with('team')
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): array => ['value' => $project->id, 'label' => $project->name, 'description' => $project->team->name])
            ->all();
    }
}

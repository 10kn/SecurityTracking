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
use App\Jobs\SyncProjectLockfilesJob;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function __construct(private UpdateProductionInventory $updateProductionInventory) {}

    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest($request, ['name', 'project', 'open_findings_count'], 'name');
        $projectId = $request->integer('project') ?: null;

        $services = $table->search(Service::query(), ['name', 'slug'])
            ->with(['project.team', ...ServiceResource::EAGER_LOADS])
            ->withCount(['findings as open_findings_count' => fn (Builder $query) => $query->where('state', FindingState::Open)])
            ->when($projectId !== null, fn (Builder $query) => $query->where('project_id', $projectId))
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
            'table' => [...$table->toArray(), 'project' => $projectId],
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

        $this->syncLockfilesWhenDirectoryChanged($service);

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
                ...$service->only(['id', 'project_id', 'name', 'slug', 'repository_path']),
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
            ],
        ]);
    }

    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        DB::transaction(function () use ($request, $service): void {
            $service->update($request->serviceAttributes());

            $this->updateProductionInventory->handle(
                $service,
                $request->runtimeVersions(),
                $request->uploadedLockfiles(),
                $request->removedLockfilePaths(),
            );
        });

        $this->syncLockfilesWhenDirectoryChanged($service);

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
     * A service added to a project that reads its lockfiles from GitHub, or moved to another directory, gets them right
     * away instead of at the next daily sync.
     */
    private function syncLockfilesWhenDirectoryChanged(Service $service): void
    {
        $project = $service->project()->first();

        if ($project?->syncs_lockfiles && ($service->wasRecentlyCreated || $service->wasChanged(['project_id', 'repository_path']))) {
            SyncProjectLockfilesJob::dispatch($project);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'projects' => $this->projectOptions(),
            'lockfileSyncProjectIds' => Project::where('syncs_lockfiles', true)->pluck('id')->all(),
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

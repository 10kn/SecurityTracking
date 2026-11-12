<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveRepositoryConnection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RepositoryConnectionRequest;
use App\Models\AccessibleRepository;
use App\Models\RepositoryConnection;
use App\Models\Service;
use App\Models\SourceHeartbeat;
use App\Services\Repositories\RepositoryReaders;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Kết nối repository" screens: the GitHub App, as `.env` configures it, and the repository connections, each the
 * personal access token of a developer account a customer invited to its repositories.
 */
class RepositoryConnectionController extends Controller
{
    public function index(RepositoryReaders $repositoryReaders): Response
    {
        $connections = RepositoryConnection::withCount(['accessibleRepositories', 'services' => fn (Builder $query) => $query->whereNotNull('repository')])
            ->orderBy('name')
            ->get();

        return Inertia::render('repository-connections/index', [
            'gitHubApp' => [
                'is_configured' => $repositoryReaders->gitHubApp()->isConfigured(),
                'repositories_count' => AccessibleRepository::whereNull('repository_connection_id')->count(),
                'services_count' => Service::whereNotNull('repository')->whereNull('repository_connection_id')->count(),
            ],
            'connections' => $connections->map(fn (RepositoryConnection $connection): array => [
                ...$this->summary($connection),
                'repositories_count' => $connection->accessible_repositories_count,
                'services_count' => $connection->services_count,
            ])->values()->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('repository-connections/create');
    }

    public function store(RepositoryConnectionRequest $request, SaveRepositoryConnection $saveRepositoryConnection): RedirectResponse
    {
        ['connection' => $connection, 'repositories_count' => $count, 'problem' => $problem] = $saveRepositoryConnection->handle(null, $request->validated());

        Inertia::flash('toast', $problem === null
            ? ['type' => 'success', 'message' => __('Đã tạo kết nối :connection: account :account đọc được :count repository.', ['connection' => $connection->name, 'account' => $connection->account_login, 'count' => $count])]
            : ['type' => 'error', 'message' => __('Đã tạo kết nối :connection nhưng chưa lấy được danh sách repository.', ['connection' => $connection->name]), 'description' => $problem]);

        return to_route('admin.repository-connections.edit', $connection);
    }

    public function edit(RepositoryConnection $repositoryConnection): Response
    {
        $services = $repositoryConnection->services()->whereNotNull('repository')->with('project')->orderBy('name')->get();

        return Inertia::render('repository-connections/edit', [
            'connection' => $this->summary($repositoryConnection),
            'repositories' => $repositoryConnection->accessibleRepositories()
                ->orderBy('full_name')
                ->get()
                ->map(fn (AccessibleRepository $repository): array => [
                    'full_name' => $repository->full_name,
                    'default_branch' => $repository->default_branch,
                    'is_private' => $repository->is_private,
                ])
                ->all(),
            'repositoriesSyncedAt' => LocalTime::format($repositoryConnection->accessibleRepositories()->latest('updated_at')->first()?->updated_at),
            'services' => $services->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'project' => $service->project->name,
                'repository' => $service->repository,
                'sync_error' => $service->lockfiles_sync_error,
            ])->all(),
        ]);
    }

    public function update(RepositoryConnectionRequest $request, RepositoryConnection $repositoryConnection): RedirectResponse
    {
        $repositoryConnection->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã lưu kết nối :connection.', ['connection' => $repositoryConnection->name])]);

        return to_route('admin.repository-connections.edit', $repositoryConnection);
    }

    /**
     * Services restrict deleting their connection, so they move to another repository first. The repositories it
     * listed and its source heartbeat go with it.
     */
    public function destroy(RepositoryConnection $repositoryConnection): RedirectResponse
    {
        if ($repositoryConnection->services()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Kết nối còn service đang dùng. Đổi repository của các service đó trước.')]);

            return back();
        }

        $repositoryConnection->delete();
        SourceHeartbeat::where('source', $repositoryConnection->sourceKey())->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã xóa kết nối :connection.', ['connection' => $repositoryConnection->name])]);

        return to_route('admin.repository-connections.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(RepositoryConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'account_login' => $connection->account_login,
            'masked_token' => $connection->maskedToken(),
            'scopes' => $connection->scopes,
            'expires_at' => LocalTime::format($connection->expires_at),
            'last_checked_at' => LocalTime::format($connection->last_checked_at),
            'last_error' => $connection->last_error,
            'status' => match (true) {
                $connection->last_error !== null => ['label' => __('Token bị từ chối'), 'tone' => 'danger'],
                $connection->expires_at?->isPast() => ['label' => __('Token đã hết hạn'), 'tone' => 'danger'],
                $connection->isExpiringSoon() => ['label' => __('Token sắp hết hạn'), 'tone' => 'warning'],
                default => ['label' => __('Hoạt động'), 'tone' => 'success'],
            },
        ];
    }
}

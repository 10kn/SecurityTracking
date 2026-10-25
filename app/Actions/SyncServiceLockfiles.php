<?php

namespace App\Actions;

use App\Enums\Ecosystem;
use App\Events\DeploymentInventoryChanged;
use App\Models\Lockfile;
use App\Models\Service;
use App\Models\SourceHeartbeat;
use App\Services\Repositories\RepositoryNotFoundException;
use App\Services\Repositories\RepositoryReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Reads a service's lockfiles from its repository (handover D12): at the tip of the service's branch, the
 * composer.lock, Gemfile.lock and package-lock.json found directly in the service's directory. The repository is then
 * the only source of the service's lockfiles: one no longer in the directory is removed, uploaded or not.
 *
 * A service whose lockfiles changed is evaluated again (DeploymentInventoryChanged), as after an upload.
 */
class SyncServiceLockfiles
{
    public function __construct(
        private RepositoryReader $repositoryReader,
        private ReplaceLockfiles $replaceLockfiles,
        private SendAdminAlert $sendAdminAlert,
    ) {}

    /**
     * Settings the host rejects (unknown repository, branch or directory, repository not shared with the App) are
     * recorded on the service and sent to the admin space once per outage. When the host cannot be reached, its
     * source heartbeat records the failure and psm:check-sources alerts the admin. Either way the lockfiles read last
     * stay in use.
     *
     * @throws Throwable When the host could not be reached.
     */
    public function handle(Service $service): void
    {
        if (! $service->syncsLockfiles()) {
            return;
        }

        if (! $this->repositoryReader->isConfigured()) {
            $this->finish($service, [__('Chưa cấu hình GitHub App (PSM_GITHUB_APP_ID, PSM_GITHUB_INSTALLATION_ID, PSM_GITHUB_PRIVATE_KEY_PATH).')]);

            return;
        }

        $heartbeat = SourceHeartbeat::updateOrCreate(['source' => $this->repositoryReader->sourceKey()], [
            'name' => $this->repositoryReader->sourceName(),
            'threshold_minutes' => config('psm.github.stale_after_minutes'),
        ]);

        try {
            $errors = $this->sync($service);
        } catch (Throwable $exception) {
            $heartbeat->update([
                'last_failure_at' => now(),
                'last_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 500),
            ]);
            $service->update(['lockfiles_sync_error' => Str::limit(__('Không kết nối được :source: :error', [
                'source' => $this->repositoryReader->sourceName(),
                'error' => $exception->getMessage(),
            ]), 1000)]);

            throw $exception;
        }

        $heartbeat->update(['last_success_at' => now(), 'last_error' => null]);

        $this->finish($service, $errors);
    }

    /**
     * @return list<string> What could not be read, one line per problem.
     *
     * @throws Throwable
     */
    private function sync(Service $service): array
    {
        $repository = (string) $service->repository;

        try {
            $commitSha = $this->repositoryReader->latestCommitSha($repository, $service->repository_branch);
        } catch (RepositoryNotFoundException|InvalidArgumentException) {
            return [__('Không đọc được repository :repository hoặc branch :branch. Kiểm tra GitHub App còn được cấp quyền cho repository này và branch còn tồn tại.', [
                'repository' => $repository,
                'branch' => $service->repository_branch ?? __('mặc định'),
            ])];
        }

        $service->update(['lockfiles_commit_sha' => $commitSha]);

        $directory = trim((string) $service->repository_path, '/');
        $shortSha = Str::substr($commitSha, 0, 7);

        try {
            $lockfileSizes = array_intersect_key($this->repositoryReader->filesIn($repository, $directory, $commitSha), Ecosystem::lockfileNames());
        } catch (RepositoryNotFoundException|InvalidArgumentException) {
            return [__('Không thấy thư mục :directory tại commit :commit.', ['directory' => '/'.$directory, 'commit' => $shortSha])];
        }

        $errors = [];
        $lockfiles = [];

        foreach ($lockfileSizes as $name => $size) {
            if ($size > Lockfile::MAX_UPLOAD_KILOBYTES * 1024) {
                $errors[] = __(':file lớn hơn 20 MB, giữ bản đã lấy trước đó.', ['file' => $name]);

                continue;
            }

            $path = ltrim("{$directory}/{$name}", '/');

            try {
                $lockfiles[] = ['name' => $name, 'content' => $this->repositoryReader->read($repository, $path, $commitSha)];
            } catch (RepositoryNotFoundException) {
                $errors[] = __('Không đọc được :file tại commit :commit.', ['file' => $path, 'commit' => $shortSha]);
            }
        }

        DB::transaction(function () use ($service, $lockfiles, $lockfileSizes, $commitSha): void {
            $deployment = $service->productionDeploymentOrCreate();
            $removedPaths = $deployment->lockfiles()->whereNotIn('path', array_keys($lockfileSizes))->pluck('path')->all();

            if ($this->replaceLockfiles->handle($deployment, $lockfiles, $removedPaths, $this->repositoryReader->lockfileSource(), $commitSha)) {
                $deployment->touch();

                DeploymentInventoryChanged::dispatch($deployment);
            }
        });

        return $errors;
    }

    /**
     * `lockfiles_synced_at` only moves on a sync without errors, so the admin alert of an outage is sent once.
     *
     * @param  list<string>  $errors
     */
    private function finish(Service $service, array $errors): void
    {
        if ($errors === []) {
            $service->update(['lockfiles_synced_at' => now(), 'lockfiles_sync_error' => null]);

            return;
        }

        $service->update(['lockfiles_sync_error' => Str::limit(implode("\n", $errors), 1000)]);
        $service->loadMissing('project');

        $this->sendAdminAlert->handle(
            "lockfile-sync:service-{$service->id}:".($service->lockfiles_synced_at?->getTimestamp() ?? 'never'),
            __('Không lấy được lockfile của service :service (project :project)', ['service' => $service->name, 'project' => $service->project->name]),
            [
                ...$errors,
                __('Repository :repository, branch :branch.', ['repository' => $service->repository, 'branch' => $service->repository_branch ?? __('mặc định')]),
                __('Thành công gần nhất: :time', ['time' => $service->lockfiles_synced_at?->translatedFormat(__('d/m/Y H:i')) ?? __('chưa có')]),
                __('Hệ thống tiếp tục dùng lockfile đã lấy trước đó.'),
            ],
            [__('Mở service') => route('admin.services.edit', $service)],
        );
    }
}

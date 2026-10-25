<?php

namespace App\Actions;

use App\Enums\Ecosystem;
use App\Events\DeploymentInventoryChanged;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Service;
use App\Models\SourceHeartbeat;
use App\Services\Repositories\RepositoryNotFoundException;
use App\Services\Repositories\RepositoryReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Reads the lockfiles of a project's services from its repository (handover D12): at the tip of the project's branch,
 * the composer.lock, Gemfile.lock and package-lock.json found directly in each service's directory. The repository is
 * then the only source of the service's lockfiles: one no longer in the directory is removed, uploaded or not.
 *
 * A service whose lockfiles changed is evaluated again (DeploymentInventoryChanged), as after an upload.
 */
class SyncProjectLockfiles
{
    public function __construct(
        private RepositoryReader $repositoryReader,
        private ReplaceLockfiles $replaceLockfiles,
        private SendAdminAlert $sendAdminAlert,
    ) {}

    /**
     * Settings the host rejects (unknown repository, branch or directory, repository not shared with the App) are
     * recorded on the project and sent to the admin space once per outage. When the host cannot be reached, its
     * source heartbeat records the failure and psm:check-sources alerts the admin. Either way the lockfiles read last
     * stay in use.
     *
     * @throws Throwable When the host could not be reached.
     */
    public function handle(Project $project): void
    {
        if (! $project->syncs_lockfiles) {
            return;
        }

        if (! $this->repositoryReader->isConfigured()) {
            $this->finish($project, [__('Chưa cấu hình GitHub App (PSM_GITHUB_APP_ID, PSM_GITHUB_INSTALLATION_ID, PSM_GITHUB_PRIVATE_KEY_PATH).')]);

            return;
        }

        $heartbeat = SourceHeartbeat::updateOrCreate(['source' => $this->repositoryReader->sourceKey()], [
            'name' => $this->repositoryReader->sourceName(),
            'threshold_minutes' => config('psm.github.stale_after_minutes'),
        ]);

        try {
            $errors = $this->sync($project);
        } catch (Throwable $exception) {
            $heartbeat->update([
                'last_failure_at' => now(),
                'last_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 500),
            ]);
            $project->update(['lockfiles_sync_error' => Str::limit(__('Không kết nối được :source: :error', [
                'source' => $this->repositoryReader->sourceName(),
                'error' => $exception->getMessage(),
            ]), 1000)]);

            throw $exception;
        }

        $heartbeat->update(['last_success_at' => now(), 'last_error' => null]);

        $this->finish($project, $errors);
    }

    /**
     * @return list<string> What could not be read, one line per problem.
     *
     * @throws Throwable
     */
    private function sync(Project $project): array
    {
        $repositoryUrl = (string) $project->repository_url;

        if (! $this->repositoryReader->supports($repositoryUrl)) {
            return [__('Repository URL không phải một repository :source.', ['source' => $this->repositoryReader->sourceName()])];
        }

        try {
            $commitSha = $this->repositoryReader->latestCommitSha($repositoryUrl, $project->repository_branch);
        } catch (RepositoryNotFoundException) {
            return [__('Không đọc được repository hoặc branch :branch. Kiểm tra repository URL, branch và GitHub App đã được cấp quyền cho repository này chưa.', [
                'branch' => $project->repository_branch ?? __('mặc định'),
            ])];
        }

        $project->update(['lockfiles_commit_sha' => $commitSha]);

        $errors = [];

        foreach ($project->services()->orderBy('name')->get() as $service) {
            array_push($errors, ...$this->syncService($repositoryUrl, $service, $commitSha));
        }

        return $errors;
    }

    /**
     * @return list<string>
     *
     * @throws Throwable
     */
    private function syncService(string $repositoryUrl, Service $service, string $commitSha): array
    {
        $directory = trim((string) $service->repository_path, '/');
        $shortSha = Str::substr($commitSha, 0, 7);

        try {
            $lockfileSizes = array_intersect_key($this->repositoryReader->filesIn($repositoryUrl, $directory, $commitSha), Ecosystem::lockfileNames());
        } catch (RepositoryNotFoundException|InvalidArgumentException) {
            return [__(':service: không thấy thư mục :directory tại commit :commit.', ['service' => $service->name, 'directory' => '/'.$directory, 'commit' => $shortSha])];
        }

        $errors = [];
        $lockfiles = [];

        foreach ($lockfileSizes as $name => $size) {
            if ($size > Lockfile::MAX_UPLOAD_KILOBYTES * 1024) {
                $errors[] = __(':service: :file lớn hơn 20 MB, giữ bản đã lấy trước đó.', ['service' => $service->name, 'file' => $name]);

                continue;
            }

            $path = ltrim("{$directory}/{$name}", '/');

            try {
                $lockfiles[] = ['name' => $name, 'content' => $this->repositoryReader->read($repositoryUrl, $path, $commitSha)];
            } catch (RepositoryNotFoundException) {
                $errors[] = __(':service: không đọc được :file tại commit :commit.', ['service' => $service->name, 'file' => $path, 'commit' => $shortSha]);
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
    private function finish(Project $project, array $errors): void
    {
        if ($errors === []) {
            $project->update(['lockfiles_synced_at' => now(), 'lockfiles_sync_error' => null]);

            return;
        }

        $project->update(['lockfiles_sync_error' => Str::limit(implode("\n", $errors), 1000)]);

        $this->sendAdminAlert->handle(
            "lockfile-sync:{$project->id}:".($project->lockfiles_synced_at?->getTimestamp() ?? 'never'),
            __('Không lấy được lockfile của project :project', ['project' => $project->name]),
            [
                ...$errors,
                __('Thành công gần nhất: :time', ['time' => $project->lockfiles_synced_at?->translatedFormat(__('d/m/Y H:i')) ?? __('chưa có')]),
                __('Hệ thống tiếp tục dùng lockfile đã lấy trước đó.'),
            ],
            [__('Mở project') => route('admin.projects.show', $project)],
        );
    }
}

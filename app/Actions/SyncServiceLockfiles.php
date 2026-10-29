<?php

namespace App\Actions;

use App\Enums\Ecosystem;
use App\Events\DeploymentInventoryChanged;
use App\Models\Lockfile;
use App\Models\Service;
use App\Models\SourceHeartbeat;
use App\Services\Repositories\IncompleteListingException;
use App\Services\Repositories\RepositoryNotFoundException;
use App\Services\Repositories\RepositoryReader;
use App\Support\LocalTime;
use App\Support\LockfilePath;
use Closure;
use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Reads a service's lockfiles from its repository (handover D12): at the tip of the service's branch, every lockfile
 * (Ecosystem::lockfileNames()) in the service's directory and its subdirectories, stored by its path relative to the
 * directory, e.g. "frontend/package-lock.json". Installed dependencies, test fixtures (`psm.github.lockfile_search`)
 * and the service's own `lockfile_excludes` are not searched. The repository is then the only source of the service's
 * lockfiles: one no longer found is removed, uploaded or not.
 *
 * A service whose lockfiles changed is evaluated again (DeploymentInventoryChanged), as after an upload.
 */
class SyncServiceLockfiles
{
    /**
     * The settings a sync reads; a sync whose settings changed while it ran keeps nothing of what it read.
     */
    private const array SETTINGS = ['repository', 'repository_branch', 'repository_path', 'lockfile_excludes'];

    /**
     * How long a sync may hold its service, and how long another sync of the service waits for it by default, in
     * seconds. A sync that outlives its lock writes nothing, so a later sync is never overwritten.
     */
    private const int LOCK_SECONDS = 600;

    public const int LOCK_WAIT_SECONDS = 60;

    public function __construct(
        private RepositoryReader $repositoryReader,
        private ReplaceLockfiles $replaceLockfiles,
        private SendAdminAlert $sendAdminAlert,
    ) {}

    /**
     * Settings the host rejects (unknown repository, branch or directory, repository not shared with the App, too
     * many lockfiles) are recorded on the service and sent to the admin space once per outage. When the host cannot be
     * reached, its source heartbeat records the failure and psm:check-sources alerts the admin. Either way the
     * lockfiles read last stay in use.
     *
     * Syncs of one service run one after the other, so an older commit never replaces a newer one.
     *
     * @param  int  $lockWaitSeconds  How long to wait for another sync of the service; 0 does not wait.
     *
     * @throws LockTimeoutException When another sync of the service did not finish in time.
     * @throws Throwable When the host could not be reached.
     */
    public function handle(Service $service, int $lockWaitSeconds = self::LOCK_WAIT_SECONDS): void
    {
        if (! $service->syncsLockfiles()) {
            return;
        }

        if (! $this->repositoryReader->isConfigured()) {
            $service->refresh();
            $this->finish($service, null, [__('Chưa cấu hình GitHub App (PSM_GITHUB_APP_ID, PSM_GITHUB_INSTALLATION_ID, PSM_GITHUB_PRIVATE_KEY_PATH).')]);

            return;
        }

        $lock = Cache::lock("lockfile-sync:service-{$service->id}", self::LOCK_SECONDS);

        $lock->block($lockWaitSeconds, function () use ($service, $lock): void {
            $service->refresh();

            if ($service->syncsLockfiles()) {
                $this->syncWithHeartbeat($service, $lock);
            }
        });
    }

    /**
     * @throws Throwable
     */
    private function syncWithHeartbeat(Service $service, Lock $lock): void
    {
        $heartbeat = SourceHeartbeat::updateOrCreate(['source' => $this->repositoryReader->sourceKey()], [
            'name' => $this->repositoryReader->sourceName(),
            'threshold_minutes' => config('psm.github.stale_after_minutes'),
        ]);

        try {
            $errors = $this->sync($service, $lock);
        } catch (Throwable $exception) {
            $heartbeat->update([
                'last_failure_at' => now(),
                'last_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 500),
            ]);
            $this->whileCurrent($service, $lock, function () use ($service, $exception): void {
                $service->update(['lockfiles_sync_error' => Str::limit(__('Không kết nối được :source: :error', [
                    'source' => $this->repositoryReader->sourceName(),
                    'error' => $exception->getMessage(),
                ]), 1000)]);
            });

            throw $exception;
        }

        $heartbeat->update(['last_success_at' => now(), 'last_error' => null]);

        $this->finish($service, $lock, $errors);
    }

    /**
     * @return list<string> What could not be read, one line per problem.
     *
     * @throws Throwable
     */
    private function sync(Service $service, Lock $lock): array
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

        $directory = trim((string) $service->repository_path, '/');
        $shortSha = Str::substr($commitSha, 0, 7);
        $search = config('psm.github.lockfile_search');

        try {
            $found = $this->repositoryReader->findFiles(
                $repository,
                $directory,
                $commitSha,
                array_keys(Ecosystem::lockfileNames()),
                (int) $search['max_depth'],
                fn (string $path): bool => $this->isExcluded($service, $path),
            );
        } catch (RepositoryNotFoundException|InvalidArgumentException) {
            return [__('Không thấy thư mục :directory tại commit :commit.', ['directory' => '/'.$directory, 'commit' => $shortSha])];
        } catch (IncompleteListingException) {
            return [__('Thư mục :directory có quá nhiều file để tìm hết lockfile tại commit :commit. Thêm thư mục không cần quét vào "Bỏ qua khi tìm lockfile", hoặc chọn thư mục hẹp hơn.', ['directory' => '/'.$directory, 'commit' => $shortSha])];
        }

        ksort($found);
        $errors = [];

        foreach (array_keys($found) as $path) {
            if (! LockfilePath::isValid($path)) {
                $errors[] = __('Bỏ qua :file: đường dẫn chỉ được gồm chữ, số và . _ @ + - (tối đa 255 ký tự).', ['file' => $path]);
                unset($found[$path]);
            }
        }

        if (count($found) > $search['max_lockfiles'] || array_sum($found) > $search['max_total_kilobytes'] * 1024) {
            return [__('Tìm thấy :count lockfile (:size MB) tại commit :commit, quá giới hạn :max lockfile / :max_size MB. Thêm thư mục không cần quét vào "Bỏ qua khi tìm lockfile".', [
                'count' => count($found),
                'size' => (int) ceil(array_sum($found) / 1024 / 1024),
                'commit' => $shortSha,
                'max' => $search['max_lockfiles'],
                'max_size' => (int) ($search['max_total_kilobytes'] / 1024),
            ])];
        }

        $lockfiles = [];

        foreach ($found as $path => $size) {
            if ($size > Lockfile::MAX_UPLOAD_KILOBYTES * 1024) {
                $errors[] = __(':file lớn hơn 20 MB, giữ bản đã lấy trước đó.', ['file' => $path]);

                continue;
            }

            try {
                $lockfiles[] = ['path' => $path, 'content' => $this->repositoryReader->read($repository, LockfilePath::join($directory, $path), $commitSha)];
            } catch (RepositoryNotFoundException) {
                $errors[] = __('Không đọc được :file tại commit :commit.', ['file' => $path, 'commit' => $shortSha]);
            }
        }

        // Only lockfiles that were not found go: one that was found but could not be read keeps its row.
        $this->whileCurrent($service, $lock, function () use ($service, $lockfiles, $found, $commitSha): void {
            $service->update(['lockfiles_commit_sha' => $commitSha]);

            $deployment = $service->productionDeploymentOrCreate();
            $removedPaths = $deployment->lockfiles()->whereNotIn('path', array_keys($found))->pluck('path')->all();

            if ($this->replaceLockfiles->handle($deployment, $lockfiles, $removedPaths, $this->repositoryReader->lockfileSource(), $commitSha)) {
                $deployment->touch();

                DeploymentInventoryChanged::dispatch($deployment);
            }
        });

        return $errors;
    }

    /**
     * A directory named in `psm.github.lockfile_search.skip_directories`, or a path the service excludes.
     */
    private function isExcluded(Service $service, string $path): bool
    {
        return in_array(basename($path), config('psm.github.lockfile_search.skip_directories'), true)
            || collect($service->lockfile_excludes ?? [])->contains(fn (string $excluded): bool => LockfilePath::isWithin($path, $excluded));
    }

    /**
     * Runs $apply only when the service still has the settings this sync read and, when the sync holds $lock, the lock
     * is still its own. The row stays locked until $apply is committed, so neither can change in between: a sync for
     * new settings is queued when they are saved, and a sync that took the expired lock waits for the row and then
     * writes its newer result.
     *
     * @param  Closure(): void  $apply
     * @return bool Whether $apply ran.
     */
    private function whileCurrent(Service $service, ?Lock $lock, Closure $apply): bool
    {
        return DB::transaction(function () use ($service, $lock, $apply): bool {
            $current = Service::whereKey($service->id)->lockForUpdate()->first();

            if ($current === null || $current->only(self::SETTINGS) != $service->only(self::SETTINGS) || ($lock !== null && ! ($lock instanceof CacheLock && $lock->isOwnedByCurrentProcess()))) {
                return false;
            }

            $apply();

            return true;
        });
    }

    /**
     * `lockfiles_synced_at` only moves on a sync without errors, so the admin alert of an outage is sent once.
     *
     * @param  ?Lock  $lock  The lock of the sync, or null when no sync ran.
     * @param  list<string>  $errors
     */
    private function finish(Service $service, ?Lock $lock, array $errors): void
    {
        if ($errors === []) {
            $this->whileCurrent($service, $lock, function () use ($service): void {
                $service->update(['lockfiles_synced_at' => now(), 'lockfiles_sync_error' => null]);
            });

            return;
        }

        $isCurrent = $this->whileCurrent($service, $lock, function () use ($service, $errors): void {
            $service->update(['lockfiles_sync_error' => Str::limit(implode("\n", $errors), 1000)]);
        });

        if (! $isCurrent) {
            return;
        }

        $service->loadMissing('project');

        $this->sendAdminAlert->handle(
            "lockfile-sync:service-{$service->id}:".($service->lockfiles_synced_at?->getTimestamp() ?? 'never'),
            __('Không lấy được lockfile của service :service (project :project)', ['service' => $service->name, 'project' => $service->project->name]),
            [
                ...$errors,
                __('Repository :repository, branch :branch.', ['repository' => $service->repository, 'branch' => $service->repository_branch ?? __('mặc định')]),
                __('Thành công gần nhất: :time', ['time' => LocalTime::format($service->lockfiles_synced_at) ?? __('chưa có')]),
                __('Hệ thống tiếp tục dùng lockfile đã lấy trước đó.'),
            ],
            [__('Mở service') => route('admin.services.edit', $service)],
        );
    }
}

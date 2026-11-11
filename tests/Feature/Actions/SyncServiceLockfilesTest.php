<?php

use App\Actions\SyncServiceLockfiles;
use App\Enums\ChatNotificationKind;
use App\Enums\Ecosystem;
use App\Enums\LockfileSource;
use App\Events\DeploymentInventoryChanged;
use App\Models\ChatNotification;
use App\Models\Deployment;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Service;
use App\Models\SourceHeartbeat;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-10-24 06:30:00');
    Event::fake([DeploymentInventoryChanged::class]);
    fakeGoogleChat();
    $this->service = Service::factory()
        ->for(Project::factory()->state(['name' => 'Shop']))
        ->syncingLockfiles('acme/shop', 'main')
        ->create(['name' => 'shop-api', 'repository_path' => '/apps/api']);
});

/**
 * @return array<string, array{source: string, commit_sha: string|null, content: string}>
 */
function lockfilesOf(Service $service): array
{
    return Lockfile::whereRelation('deployment', 'service_id', $service->id)
        ->orderBy('path')
        ->get()
        ->mapWithKeys(fn (Lockfile $lockfile): array => [$lockfile->path => ['source' => $lockfile->source->value, 'commit_sha' => $lockfile->commit_sha, 'content' => $lockfile->content]])
        ->all();
}

function adminAlerts(): int
{
    return ChatNotification::where('kind', ChatNotificationKind::AdminAlert)->count();
}

it('reads the lockfiles in the directory of the service at the tip of its branch', function () {
    fakeGitHub([
        'apps/api/composer.lock' => '{"packages":[]}',
        'apps/api/README.md' => '# api',
        'package-lock.json' => '{"lockfileVersion":3}',
    ]);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(lockfilesOf($this->service))->toBe(['composer.lock' => ['source' => 'github', 'commit_sha' => GITHUB_COMMIT, 'content' => '{"packages":[]}']]);

    expect($this->service->fresh())
        ->lockfiles_synced_at->toEqual(now())
        ->lockfiles_commit_sha->toBe(GITHUB_COMMIT)
        ->lockfiles_sync_error->toBeNull();

    parse_str((string) parse_url(gitHubRequests('repos/acme/shop/commits')[0]->url(), PHP_URL_QUERY), $query);
    expect($query['sha'])->toBe('main');

    Event::assertDispatchedTimes(DeploymentInventoryChanged::class, 1);
    expect(SourceHeartbeat::firstWhere('source', 'github'))->last_success_at->toEqual(now())->threshold_minutes->toBe(1500);
});

it('reads each service from its own repository and the default branch when none is set', function () {
    $web = Service::factory()->syncingLockfiles('acme/storefront')->create(['repository_path' => '/']);
    fakeGitHub(['package-lock.json' => '{"lockfileVersion":3}']);

    app(SyncServiceLockfiles::class)->handle($web);

    expect(array_keys(lockfilesOf($web)))->toBe(['package-lock.json'])
        ->and(gitHubRequests('repos/acme/shop'))->toHaveCount(0);

    parse_str((string) parse_url(gitHubRequests('repos/acme/storefront/commits')[0]->url(), PHP_URL_QUERY), $query);
    expect($query)->not->toHaveKey('sha');
});

it('reads pnpm and yarn lockfiles as npm lockfiles', function () {
    fakeGitHub([
        'apps/api/pnpm-lock.yaml' => lockfileFixture('pnpm-lock.yaml'),
        'apps/api/yarn.lock' => lockfileFixture('yarn.lock'),
        'apps/api/bun.lock' => '{}',
    ]);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect($this->service->productionDeployment->lockfiles()->pluck('ecosystem', 'path')->map->value->all())->toEqual(['pnpm-lock.yaml' => 'npm', 'yarn.lock' => 'npm'])
        ->and($this->service->fresh()->lockfiles_sync_error)->toBeNull();
});

it('finds the lockfiles in subdirectories of the service, by their path in the service directory', function () {
    fakeGitHub([
        'apps/api/composer.lock' => '{"packages":[]}',
        'apps/api/frontend/package-lock.json' => '{"lockfileVersion":3}',
        'apps/api/tools/ruby/Gemfile.lock' => 'GEM',
        'apps/api/a/b/c/d/package-lock.json' => '{}',
        'apps/api/a/b/c/d/e/package-lock.json' => '{}',
        'apps/web/package-lock.json' => '{}',
    ]);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(array_keys(lockfilesOf($this->service)))->toBe(['a/b/c/d/package-lock.json', 'composer.lock', 'frontend/package-lock.json', 'tools/ruby/Gemfile.lock'])
        ->and(Lockfile::where('path', 'frontend/package-lock.json')->sole()->ecosystem)->toBe(Ecosystem::Npm)
        ->and(Lockfile::where('path', 'tools/ruby/Gemfile.lock')->sole()->ecosystem)->toBe(Ecosystem::Rubygems)
        ->and(gitHubRequests('repos/acme/shop/contents/apps/api/frontend/package-lock.json'))->toHaveCount(1)
        ->and($this->service->fresh()->lockfiles_sync_error)->toBeNull();

    expect(gitHubRequests('repos/acme/shop/git/trees/')->map(fn (Request $request): string => Str::after(urldecode($request->url()), 'git/trees/'))->all())
        // The last one looks for .github at the root of the repository, for GitHub Actions workflows.
        ->toBe([GITHUB_COMMIT, fakeTreeSha('apps'), fakeTreeSha('apps/api').'?recursive=1', GITHUB_COMMIT]);
});

it('leaves out installed dependencies, test fixtures and the paths the service excludes', function () {
    $this->service->update(['lockfile_excludes' => ['legacy', 'tools/old/package-lock.json']]);
    fakeGitHub([
        'apps/api/composer.lock' => '{}',
        'apps/api/node_modules/left-pad/package-lock.json' => '{}',
        'apps/api/vendor/acme/lib/composer.lock' => '{}',
        'apps/api/tests/Fixtures/composer.lock' => '{}',
        'apps/api/legacy/package-lock.json' => '{}',
        'apps/api/legacy-ui/package-lock.json' => '{}',
        'apps/api/tools/old/package-lock.json' => '{}',
        'apps/api/tools/new/package-lock.json' => '{}',
        'apps/api/.github/actions/notify/package-lock.json' => '{}',
    ]);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(array_keys(lockfilesOf($this->service)))->toBe(['.github/actions/notify/package-lock.json', 'composer.lock', 'legacy-ui/package-lock.json', 'tools/new/package-lock.json']);
});

it('lists a directory GitHub cuts short level by level, without entering skipped directories', function () {
    fakeGitHub([
        'apps/api/composer.lock' => '{}',
        'apps/api/frontend/package-lock.json' => '{}',
        'apps/api/node_modules/left-pad/package-lock.json' => '{}',
    ], truncatedTrees: ['apps/api']);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(array_keys(lockfilesOf($this->service)))->toBe(['composer.lock', 'frontend/package-lock.json'])
        ->and(gitHubRequests('repos/acme/shop/git/trees/'.fakeTreeSha('apps/api/frontend')))->toHaveCount(1)
        ->and(gitHubRequests('repos/acme/shop/git/trees/'.fakeTreeSha('apps/api/node_modules')))->toHaveCount(0);

    gitHubRequests('repos/acme/shop/git/trees/'.fakeTreeSha('apps/api/frontend'))
        ->each(fn (Request $request) => expect($request->url())->not->toContain('recursive'));
});

it('keeps every lockfile and alerts the admin once when the directory is too large to list', function (array $limits, string $error) {
    config(['psm.github.lockfile_search' => [...config('psm.github.lockfile_search'), ...$limits]]);
    $deployment = Deployment::factory()->for($this->service)->create();
    Lockfile::factory()->for($deployment)->fromGitHub(str_repeat('a', 40))->withContent('legacy/package-lock.json', '{}')->create();
    fakeGitHub([
        'apps/api/composer.lock' => '{}',
        'apps/api/a/package-lock.json' => '{}',
        'apps/api/b/package-lock.json' => '{}',
    ], truncatedTrees: ['apps/api']);

    app(SyncServiceLockfiles::class)->handle($this->service);
    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(array_keys(lockfilesOf($this->service)))->toBe(['legacy/package-lock.json'])
        ->and($this->service->fresh()->lockfiles_sync_error)->toContain($error)
        ->and(adminAlerts())->toBe(1);
    Event::assertNotDispatched(DeploymentInventoryChanged::class);
})->with([
    'too many trees' => [['max_tree_requests' => 4], 'quá nhiều file để tìm hết lockfile tại commit 3f78685'],
    'too many lockfiles' => [['max_lockfiles' => 2], 'Tìm thấy 3 lockfile (1 MB) tại commit 3f78685, quá giới hạn 2 lockfile'],
    'too large' => [['max_total_kilobytes' => 0], 'quá giới hạn 20 lockfile / 0 MB'],
]);

it('stops when a level of the walk is cut short too', function () {
    Http::fake(['https://api.github.com/repos/*/git/trees/'.fakeTreeSha('apps/api/a') => Http::response(['sha' => fakeTreeSha('apps/api/a'), 'tree' => [], 'truncated' => true])]);
    fakeGitHub(['apps/api/composer.lock' => '{}', 'apps/api/a/package-lock.json' => '{}'], truncatedTrees: ['apps/api']);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(lockfilesOf($this->service))->toBe([])
        ->and($this->service->fresh()->lockfiles_sync_error)->toContain('quá nhiều file');
});

it('reports a lockfile at a path the hub does not take and reads the others', function () {
    fakeGitHub(['apps/api/composer.lock' => '{}', 'apps/api/my app/package-lock.json' => '{}']);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(array_keys(lockfilesOf($this->service)))->toBe(['composer.lock'])
        ->and($this->service->fresh()->lockfiles_sync_error)->toStartWith('Bỏ qua my app/package-lock.json');
});

it('keeps nothing it read when the settings of the service changed meanwhile', function () {
    Http::fake(['https://api.github.com/repos/*/contents/apps/api/composer.lock*' => function () {
        Service::whereKey($this->service->id)->update(['repository_path' => '/apps/web']);

        return Http::response('{}');
    }]);
    fakeGitHub(['apps/api/composer.lock' => '{}']);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(lockfilesOf($this->service))->toBe([])
        ->and($this->service->fresh())->lockfiles_commit_sha->toBeNull()->lockfiles_synced_at->toBeNull();
    Event::assertNotDispatched(DeploymentInventoryChanged::class);
});

it('writes nothing once another sync took its expired lock', function () {
    Http::fake(['https://api.github.com/repos/*/contents/apps/api/composer.lock*' => function () {
        Cache::lock("lockfile-sync:service-{$this->service->id}")->forceRelease();
        Cache::lock("lockfile-sync:service-{$this->service->id}", 600)->get();

        return Http::response('{}');
    }]);
    fakeGitHub(['apps/api/composer.lock' => '{}']);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(lockfilesOf($this->service))->toBe([])
        ->and($this->service->fresh())->lockfiles_commit_sha->toBeNull()->lockfiles_synced_at->toBeNull();
    Event::assertNotDispatched(DeploymentInventoryChanged::class);
});

it('waits for another sync of the same service', function () {
    Sleep::fake(syncWithCarbon: true);
    fakeGitHub(['apps/api/composer.lock' => '{}']);
    $lock = Cache::lock("lockfile-sync:service-{$this->service->id}", 600);
    $lock->get();

    expect(fn () => app(SyncServiceLockfiles::class)->handle($this->service))->toThrow(LockTimeoutException::class)
        ->and(gitHubRequests('repos/'))->toHaveCount(0);

    $lock->release();
    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(array_keys(lockfilesOf($this->service)))->toBe(['composer.lock']);
});

it('evaluates nothing again when the lockfiles did not change', function () {
    $commit = GITHUB_COMMIT;
    Http::fake(['https://api.github.com/repos/*/commits*' => function () use (&$commit) {
        return Http::response([['sha' => $commit]]);
    }]);
    fakeGitHub(['apps/api/composer.lock' => '{}']);
    app(SyncServiceLockfiles::class)->handle($this->service);
    Event::fake([DeploymentInventoryChanged::class]);

    $commit = str_repeat('b', 40);
    app(SyncServiceLockfiles::class)->handle($this->service);

    Event::assertNotDispatched(DeploymentInventoryChanged::class);
    expect(lockfilesOf($this->service)['composer.lock']['commit_sha'])->toBe($commit)
        ->and($this->service->fresh()->lockfiles_commit_sha)->toBe($commit);
});

it('removes the lockfiles no longer in the directory, uploaded or not', function () {
    $deployment = Deployment::factory()->for($this->service)->create();
    Lockfile::factory()->for($deployment)->withContent('Gemfile.lock', 'GEM')->create();
    Lockfile::factory()->for($deployment)->fromGitHub(str_repeat('a', 40))->withContent('package-lock.json', '{}')->create();
    fakeGitHub(['apps/api/composer.lock' => '{}']);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(array_keys(lockfilesOf($this->service)))->toBe(['composer.lock']);
});

it('replaces an uploaded lockfile with the one in the repository', function () {
    $deployment = Deployment::factory()->for($this->service)->create();
    Lockfile::factory()->for($deployment)->withContent('composer.lock', '{"old":true}')->create();
    fakeGitHub(['apps/api/composer.lock' => '{}']);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(lockfilesOf($this->service))->toBe(['composer.lock' => ['source' => 'github', 'commit_sha' => GITHUB_COMMIT, 'content' => '{}']]);
});

it('keeps the lockfiles when the directory is missing and alerts the admin once', function () {
    $deployment = Deployment::factory()->for($this->service)->create();
    Lockfile::factory()->for($deployment)->fromGitHub(str_repeat('a', 40))->withContent('composer.lock', '{}')->create();
    fakeGitHub(['package-lock.json' => '{"lockfileVersion":3}']);

    app(SyncServiceLockfiles::class)->handle($this->service);
    $this->travel(1)->day();
    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(array_keys(lockfilesOf($this->service)))->toBe(['composer.lock']);

    expect($this->service->fresh())
        ->lockfiles_synced_at->toBeNull()
        ->lockfiles_sync_error->toBe('Không thấy thư mục /apps/api tại commit 3f78685.');

    expect(adminAlerts())->toBe(1)
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))
        ->toContain('Không lấy được lockfile của service shop-api (project Shop)')
        ->toContain('Không thấy thư mục /apps/api')
        ->toContain('Repository acme/shop, branch main.');
});

it('alerts again after a sync without errors', function () {
    fakeGitHub(['package-lock.json' => '{}']);
    app(SyncServiceLockfiles::class)->handle($this->service);
    $this->service->update(['repository_path' => '/']);
    app(SyncServiceLockfiles::class)->handle($this->service);
    $this->service->update(['repository_path' => '/apps/gone']);
    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(adminAlerts())->toBe(2);
});

it('reports a branch or repository github does not know', function () {
    Http::fake(['https://api.github.com/repos/*/commits*' => Http::response(['message' => 'No commit found for SHA: main'], 422)]);
    fakeGitHub();

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect($this->service->fresh()->lockfiles_sync_error)->toStartWith('Không đọc được repository acme/shop hoặc branch main.')
        ->and(adminAlerts())->toBe(1)
        ->and(SourceHeartbeat::firstWhere('source', 'github')->last_success_at)->toEqual(now());
});

it('keeps the lockfiles while github is down', function () {
    Sleep::fake();
    $deployment = Deployment::factory()->for($this->service)->create();
    Lockfile::factory()->for($deployment)->fromGitHub(str_repeat('a', 40))->withContent('composer.lock', '{}')->create();
    Http::fake(['https://api.github.com/repos/*' => Http::response('Service Unavailable', 503)]);
    fakeGitHub();

    expect(fn () => app(SyncServiceLockfiles::class)->handle($this->service))->toThrow(RequestException::class);

    expect(array_keys(lockfilesOf($this->service)))->toBe(['composer.lock'])
        ->and($this->service->fresh()->lockfiles_sync_error)->toStartWith('Không kết nối được GitHub:')
        ->and(SourceHeartbeat::firstWhere('source', 'github'))->last_failure_at->toEqual(now())->last_success_at->toBeNull();

    expect(adminAlerts())->toBe(0);
});

it('keeps a lockfile larger than the upload limit as it was', function () {
    $deployment = Deployment::factory()->for($this->service)->create();
    Lockfile::factory()->for($deployment)->fromGitHub(str_repeat('a', 40))->withContent('composer.lock', '{}')->create();
    fakeGitHub(['apps/api/composer.lock' => str_repeat(' ', Lockfile::MAX_UPLOAD_KILOBYTES * 1024 + 1)]);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect(lockfilesOf($this->service)['composer.lock']['content'])->toBe('{}')
        ->and($this->service->fresh()->lockfiles_sync_error)->toContain('composer.lock lớn hơn 20 MB')
        ->and(gitHubRequests('repos/acme/shop/contents/apps/api/composer.lock'))->toHaveCount(0);
});

it('records that the github app is not configured', function () {
    Http::preventStrayRequests();
    fakeGoogleChat();

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect($this->service->fresh()->lockfiles_sync_error)->toStartWith('Chưa cấu hình GitHub App')
        ->and(adminAlerts())->toBe(1)
        ->and(SourceHeartbeat::where('source', 'github')->exists())->toBeFalse();
});

it('leaves services without a repository alone', function () {
    Http::preventStrayRequests();
    $this->service->update(['repository' => null]);

    app(SyncServiceLockfiles::class)->handle($this->service);

    expect($this->service->fresh()->lockfiles_sync_error)->toBeNull()
        ->and(Lockfile::count())->toBe(0);
});

it('stores the source of uploaded lockfiles as upload', function () {
    expect(Lockfile::factory()->create()->source)->toBe(LockfileSource::Upload);
});

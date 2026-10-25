<?php

use App\Actions\SyncProjectLockfiles;
use App\Enums\ChatNotificationKind;
use App\Enums\LockfileSource;
use App\Events\DeploymentInventoryChanged;
use App\Models\ChatNotification;
use App\Models\Deployment;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Service;
use App\Models\SourceHeartbeat;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->travelTo('2026-10-24 06:30:00');
    Event::fake([DeploymentInventoryChanged::class]);
    fakeGoogleChat();
    $this->project = Project::factory()->syncingLockfiles('main')->create(['name' => 'Shop', 'repository_url' => 'https://github.com/acme/shop']);
    $this->api = Service::factory()->for($this->project)->create(['name' => 'shop-api', 'repository_path' => '/apps/api']);
    $this->web = Service::factory()->for($this->project)->create(['name' => 'shop-web', 'repository_path' => '/']);
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

it('reads the lockfiles in the directory of each service at the tip of the branch', function () {
    fakeGitHub([
        'apps/api/composer.lock' => '{"packages":[]}',
        'apps/api/README.md' => '# api',
        'package-lock.json' => '{"lockfileVersion":3}',
        'Gemfile' => "source 'https://rubygems.org'",
    ]);

    app(SyncProjectLockfiles::class)->handle($this->project);

    expect(lockfilesOf($this->api))->toBe(['composer.lock' => ['source' => 'github', 'commit_sha' => GITHUB_COMMIT, 'content' => '{"packages":[]}']])
        ->and(lockfilesOf($this->web))->toBe(['package-lock.json' => ['source' => 'github', 'commit_sha' => GITHUB_COMMIT, 'content' => '{"lockfileVersion":3}']]);

    expect($this->project->fresh())
        ->lockfiles_synced_at->toEqual(now())
        ->lockfiles_commit_sha->toBe(GITHUB_COMMIT)
        ->lockfiles_sync_error->toBeNull();

    parse_str((string) parse_url(gitHubRequests('repos/acme/shop/commits')[0]->url(), PHP_URL_QUERY), $query);
    expect($query['sha'])->toBe('main');

    Event::assertDispatchedTimes(DeploymentInventoryChanged::class, 2);
    expect(SourceHeartbeat::firstWhere('source', 'github'))->last_success_at->toEqual(now())->threshold_minutes->toBe(1500);
});

it('evaluates nothing again when the lockfiles did not change', function () {
    $commit = GITHUB_COMMIT;
    Http::fake(['https://api.github.com/repos/*/commits*' => function () use (&$commit) {
        return Http::response([['sha' => $commit]]);
    }]);
    fakeGitHub(['package-lock.json' => '{"lockfileVersion":3}']);
    app(SyncProjectLockfiles::class)->handle($this->project);
    Event::fake([DeploymentInventoryChanged::class]);

    $commit = str_repeat('b', 40);
    app(SyncProjectLockfiles::class)->handle($this->project);

    Event::assertNotDispatched(DeploymentInventoryChanged::class);
    expect(lockfilesOf($this->web)['package-lock.json']['commit_sha'])->toBe($commit)
        ->and($this->project->fresh()->lockfiles_commit_sha)->toBe($commit);
});

it('removes the lockfiles no longer in the directory, uploaded or not', function () {
    $deployment = Deployment::factory()->for($this->web)->create();
    Lockfile::factory()->for($deployment)->withContent('Gemfile.lock', 'GEM')->create();
    Lockfile::factory()->for($deployment)->fromGitHub(str_repeat('a', 40))->withContent('composer.lock', '{}')->create();
    fakeGitHub(['package-lock.json' => '{"lockfileVersion":3}']);

    app(SyncProjectLockfiles::class)->handle($this->project);

    expect(array_keys(lockfilesOf($this->web)))->toBe(['package-lock.json']);
});

it('replaces an uploaded lockfile with the one in the repository', function () {
    $deployment = Deployment::factory()->for($this->web)->create();
    Lockfile::factory()->for($deployment)->withContent('package-lock.json', '{"lockfileVersion":2}')->create();
    fakeGitHub(['package-lock.json' => '{"lockfileVersion":3}']);

    app(SyncProjectLockfiles::class)->handle($this->project);

    expect(lockfilesOf($this->web))->toBe(['package-lock.json' => ['source' => 'github', 'commit_sha' => GITHUB_COMMIT, 'content' => '{"lockfileVersion":3}']]);
});

it('keeps the lockfiles of a service whose directory is missing and alerts the admin once', function () {
    $deployment = Deployment::factory()->for($this->api)->create();
    Lockfile::factory()->for($deployment)->fromGitHub(str_repeat('a', 40))->withContent('composer.lock', '{}')->create();
    fakeGitHub(['package-lock.json' => '{"lockfileVersion":3}']);

    app(SyncProjectLockfiles::class)->handle($this->project);
    $this->travel(1)->day();
    app(SyncProjectLockfiles::class)->handle($this->project);

    expect(array_keys(lockfilesOf($this->api)))->toBe(['composer.lock'])
        ->and(array_keys(lockfilesOf($this->web)))->toBe(['package-lock.json']);

    expect($this->project->fresh())
        ->lockfiles_synced_at->toBeNull()
        ->lockfiles_sync_error->toBe('shop-api: không thấy thư mục /apps/api tại commit 3f78685.');

    expect(adminAlerts())->toBe(1)
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Không lấy được lockfile của project Shop')->toContain('shop-api: không thấy thư mục /apps/api');
});

it('alerts again after a sync without errors', function () {
    fakeGitHub(['package-lock.json' => '{}']);
    app(SyncProjectLockfiles::class)->handle($this->project);
    $this->api->update(['repository_path' => '/']);
    app(SyncProjectLockfiles::class)->handle($this->project);
    $this->api->update(['repository_path' => '/apps/gone']);
    app(SyncProjectLockfiles::class)->handle($this->project);

    expect(adminAlerts())->toBe(2);
});

it('reports a branch or repository github does not know', function () {
    Http::fake(['https://api.github.com/repos/*/commits*' => Http::response(['message' => 'No commit found for SHA: main'], 422)]);
    fakeGitHub();

    app(SyncProjectLockfiles::class)->handle($this->project);

    expect($this->project->fresh()->lockfiles_sync_error)->toStartWith('Không đọc được repository hoặc branch main.')
        ->and(adminAlerts())->toBe(1)
        ->and(SourceHeartbeat::firstWhere('source', 'github')->last_success_at)->toEqual(now());
});

it('keeps the lockfiles while github is down', function () {
    Sleep::fake();
    $deployment = Deployment::factory()->for($this->web)->create();
    Lockfile::factory()->for($deployment)->fromGitHub(str_repeat('a', 40))->withContent('package-lock.json', '{}')->create();
    Http::fake(['https://api.github.com/repos/*' => Http::response('Service Unavailable', 503)]);
    fakeGitHub();

    expect(fn () => app(SyncProjectLockfiles::class)->handle($this->project))->toThrow(RequestException::class);

    expect(array_keys(lockfilesOf($this->web)))->toBe(['package-lock.json'])
        ->and($this->project->fresh()->lockfiles_sync_error)->toStartWith('Không kết nối được GitHub:')
        ->and(SourceHeartbeat::firstWhere('source', 'github'))->last_failure_at->toEqual(now())->last_success_at->toBeNull();

    expect(adminAlerts())->toBe(0);
});

it('keeps a lockfile larger than the upload limit as it was', function () {
    $deployment = Deployment::factory()->for($this->web)->create();
    Lockfile::factory()->for($deployment)->fromGitHub(str_repeat('a', 40))->withContent('package-lock.json', '{}')->create();
    fakeGitHub(['package-lock.json' => str_repeat(' ', Lockfile::MAX_UPLOAD_KILOBYTES * 1024 + 1)]);

    app(SyncProjectLockfiles::class)->handle($this->project);

    expect(lockfilesOf($this->web)['package-lock.json']['content'])->toBe('{}')
        ->and($this->project->fresh()->lockfiles_sync_error)->toContain('shop-web: package-lock.json lớn hơn 20 MB')
        ->and(gitHubRequests('repos/acme/shop/contents/package-lock.json'))->toHaveCount(0);
});

it('records that the github app is not configured', function () {
    Http::preventStrayRequests();
    fakeGoogleChat();

    app(SyncProjectLockfiles::class)->handle($this->project);

    expect($this->project->fresh()->lockfiles_sync_error)->toStartWith('Chưa cấu hình GitHub App')
        ->and(adminAlerts())->toBe(1)
        ->and(SourceHeartbeat::where('source', 'github')->exists())->toBeFalse();
});

it('leaves projects that do not sync their lockfiles alone', function () {
    Http::preventStrayRequests();
    $this->project->update(['syncs_lockfiles' => false]);

    app(SyncProjectLockfiles::class)->handle($this->project);

    expect($this->project->fresh()->lockfiles_sync_error)->toBeNull()
        ->and(Lockfile::count())->toBe(0);
});

it('stores the source of uploaded lockfiles as upload', function () {
    expect(Lockfile::factory()->create()->source)->toBe(LockfileSource::Upload);
});

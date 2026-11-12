<?php

use App\Jobs\SyncServiceLockfilesJob;
use App\Models\AccessibleRepository;
use App\Models\RepositoryConnection;
use App\Models\Service;
use App\Models\SourceHeartbeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\delete;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/**
 * Fakes GitHub for a personal access token: who it signs in as, and the repositories it may read.
 *
 * @param  list<string>  $repositories
 */
function fakeGitHubAccount(string $login = 'dev-abc', ?string $scopes = 'repo', ?string $expiration = '2027-01-10 07:00:00 UTC', array $repositories = ['customer/shop']): void
{
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/user' => Http::response(['login' => $login], 200, array_filter(['X-OAuth-Scopes' => $scopes, 'GitHub-Authentication-Token-Expiration' => $expiration], fn (?string $value): bool => $value !== null)),
        'https://api.github.com/user/repos*' => Http::response(array_map(fn (string $fullName): array => ['full_name' => $fullName, 'default_branch' => 'main', 'private' => true], $repositories)),
    ]);
}

beforeEach(function () {
    $this->travelTo('2026-11-12 09:00:00');
    config(['inertia.ssr.enabled' => false]);
});

it('lists the github app and every connection with its state', function () {
    actingAsAdmin();
    $connection = RepositoryConnection::factory()->create(['name' => 'Khách ABC', 'account_login' => 'dev-abc', 'expires_at' => '2026-11-20 00:00:00']);
    AccessibleRepository::factory()->count(2)->create(['repository_connection_id' => $connection->id]);
    AccessibleRepository::factory()->create();
    Service::factory()->syncingLockfiles('customer/shop', connection: $connection)->create();

    get('/admin/repository-connections')->assertInertia(fn (Assert $page) => $page
        ->component('repository-connections/index')
        ->where('gitHubApp', ['is_configured' => false, 'repositories_count' => 1, 'services_count' => 0])
        ->where('connections.0.name', 'Khách ABC')
        ->where('connections.0.account_login', 'dev-abc')
        ->where('connections.0.repositories_count', 2)
        ->where('connections.0.services_count', 1)
        ->where('connections.0.status', ['label' => 'Token sắp hết hạn', 'tone' => 'warning'])
        ->missing('connections.0.token'));
});

it('creates a connection once github accepts its token, and lists the repositories its account may read', function () {
    actingAsAdmin();
    fakeGitHubAccount(repositories: ['customer/shop', 'customer/api']);

    post('/admin/repository-connections', ['name' => 'Khách ABC', 'token' => 'ghp_customer'])
        ->assertRedirect('/admin/repository-connections/'.RepositoryConnection::sole()->id.'/edit')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Đã tạo kết nối Khách ABC: account dev-abc đọc được 2 repository.']);

    $connection = RepositoryConnection::sole();
    expect($connection)
        ->token->toBe('ghp_customer')
        ->account_login->toBe('dev-abc')
        ->scopes->toBe('repo')
        ->expires_at->toEqual(now()->setDateTime(2027, 1, 10, 7, 0))
        ->last_checked_at->toEqual(now())
        ->and(DB::table('repository_connections')->value('token'))->not->toContain('ghp_customer')
        ->and($connection->accessibleRepositories()->orderBy('full_name')->pluck('full_name')->all())->toBe(['customer/api', 'customer/shop'])
        ->and(gitHubRequests('user')[0]->header('Authorization'))->toBe(['Bearer ghp_customer']);
});

it('keeps a fine-grained token without scopes or expiry', function () {
    actingAsAdmin();
    fakeGitHubAccount(scopes: null, expiration: null);

    post('/admin/repository-connections', ['name' => 'Khách ABC', 'token' => 'github_pat_11ABC'])->assertSessionHasNoErrors();

    expect(RepositoryConnection::sole())->scopes->toBeNull()->expires_at->toBeNull();
});

it('saves no token github turns down', function (int $status, array $headers, string $error) {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/user' => Http::response(['message' => 'Bad credentials'], $status, $headers)]);

    from('/admin/repository-connections/create')->post('/admin/repository-connections', ['name' => 'Khách ABC', 'token' => 'ghp_revoked'])
        ->assertRedirect('/admin/repository-connections/create')
        ->assertSessionHasErrors(['token' => $error]);

    expect(RepositoryConnection::count())->toBe(0);
})->with([
    'revoked' => [401, [], 'GitHub không nhận token: token đã bị thu hồi, hết hạn hoặc sai.'],
    'sso' => [403, ['X-GitHub-SSO' => 'required; url=https://github.com/orgs/acme/sso'], 'Tổ chức trên GitHub bật SAML SSO: account cần Authorize SSO cho token với tổ chức này.'],
]);

it('takes only a token-shaped value', function () {
    actingAsAdmin();
    Http::preventStrayRequests();

    post('/admin/repository-connections', ['name' => '', 'token' => "ghp_x\r\nX-Evil: 1"])->assertSessionHasErrors(['name', 'token']);
});

it('shows a connection with its token masked, its repositories and the services reading through it', function () {
    actingAsAdmin();
    $connection = RepositoryConnection::factory()->create(['name' => 'Khách ABC', 'token' => 'ghp_abcdefghijklmnopqrstuvwxyz0123456789']);
    AccessibleRepository::factory()->create(['repository_connection_id' => $connection->id, 'full_name' => 'customer/shop']);
    $service = Service::factory()->syncingLockfiles('customer/shop', connection: $connection)->create(['name' => 'shop-api']);

    get("/admin/repository-connections/{$connection->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->component('repository-connections/edit')
        ->where('connection.masked_token', 'ghp_••••6789')
        ->where('repositories', [['full_name' => 'customer/shop', 'default_branch' => 'main', 'is_private' => true]])
        ->where('services.0.id', $service->id)
        ->where('services.0.name', 'shop-api'));
});

it('renames a connection', function () {
    actingAsAdmin();
    $connection = RepositoryConnection::factory()->create(['token' => 'ghp_kept']);
    Http::preventStrayRequests();

    put("/admin/repository-connections/{$connection->id}", ['name' => 'Khách XYZ'])->assertSessionHasNoErrors();

    expect($connection->fresh())->name->toBe('Khách XYZ')->token->toBe('ghp_kept');
});

it('replaces the token once github accepts the new one and reads the services through it again', function () {
    actingAsAdmin();
    Queue::fake([SyncServiceLockfilesJob::class]);
    $connection = RepositoryConnection::factory()->rejected()->create(['token' => 'ghp_old']);
    $service = Service::factory()->syncingLockfiles('customer/shop', connection: $connection)->create();
    fakeGitHubAccount(login: 'dev-new');

    put("/admin/repository-connections/{$connection->id}/token", ['token' => 'ghp_new'])
        ->assertInertiaFlash('toast.message', 'Đã thay token: account dev-new đọc được 1 repository.');

    expect($connection->fresh())->token->toBe('ghp_new')->account_login->toBe('dev-new')->last_error->toBeNull();
    Queue::assertPushed(SyncServiceLockfilesJob::class, fn (SyncServiceLockfilesJob $job): bool => $job->service->is($service));
});

it('keeps the old token when github turns the new one down', function () {
    actingAsAdmin();
    $connection = RepositoryConnection::factory()->create(['token' => 'ghp_old']);
    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);

    put("/admin/repository-connections/{$connection->id}/token", ['token' => 'ghp_wrong'])->assertSessionHasErrors('token');

    expect($connection->fresh()->token)->toBe('ghp_old');
});

it('refreshes the repositories of one connection and keeps the others', function () {
    actingAsAdmin();
    $connection = RepositoryConnection::factory()->create();
    AccessibleRepository::factory()->create(['repository_connection_id' => $connection->id, 'full_name' => 'customer/gone']);
    AccessibleRepository::factory()->create(['full_name' => 'acme/shop']);
    fakeGitHubAccount(repositories: ['customer/shop']);

    post('/admin/repositories/sync', ['repository_connection_id' => $connection->id])
        ->assertInertiaFlash('toast.message', 'Đã cập nhật danh sách: 1 repository.');

    expect(AccessibleRepository::orderBy('full_name')->get()->map(fn (AccessibleRepository $repository): array => [$repository->repository_connection_id, $repository->full_name])->all())
        ->toBe([[null, 'acme/shop'], [$connection->id, 'customer/shop']]);
});

it('deletes a connection no service reads through, with its repositories and heartbeat', function () {
    actingAsAdmin();
    $connection = RepositoryConnection::factory()->create(['name' => 'Khách ABC']);
    AccessibleRepository::factory()->create(['repository_connection_id' => $connection->id]);
    SourceHeartbeat::factory()->create(['source' => $connection->sourceKey()]);

    delete("/admin/repository-connections/{$connection->id}")->assertRedirect('/admin/repository-connections');

    expect(RepositoryConnection::count())->toBe(0)
        ->and(AccessibleRepository::count())->toBe(0)
        ->and(SourceHeartbeat::count())->toBe(0);
});

it('keeps a connection a service still reads through', function () {
    actingAsAdmin();
    $connection = RepositoryConnection::factory()->create();
    Service::factory()->syncingLockfiles('customer/shop', connection: $connection)->create();

    delete("/admin/repository-connections/{$connection->id}")
        ->assertInertiaFlash('toast.message', 'Kết nối còn service đang dùng. Đổi repository của các service đó trước.');

    expect($connection->fresh())->not->toBeNull();
});

it('sends a guest to the login page', function () {
    get('/admin/repository-connections')->assertRedirect('/admin/login');
});

<?php

use App\Actions\UpdateProductionInventory;
use App\Enums\OperatingSystem;
use App\Enums\RuntimeStatus;
use App\Enums\Technology;
use App\Events\DeploymentInventoryChanged;
use App\Jobs\SyncServiceLockfilesJob;
use App\Models\AccessibleRepository;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\assertModelMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

/**
 * The fields of the service form, with the project and name filled in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function serviceForm(array $overrides = []): array
{
    return [
        'project_id' => $overrides['project_id'] ?? Project::factory()->create()->id,
        'name' => 'shop-api',
        'slug' => 'shop-api',
        'kind' => 'ecs',
        'exposure' => 'public',
        'repository_path' => '/',
        'runtimes' => [],
        ...$overrides,
    ];
}

it('creates a service with its production runtimes and lockfile and queues an evaluation', function () {
    actingAsAdmin();
    $php = Technology::Php;
    $nodejs = Technology::Nodejs;
    Event::fake([DeploymentInventoryChanged::class]);

    $response = post('/admin/services', serviceForm([
        'runtimes' => [
            ['technology' => $php->value, 'raw_version' => '8.3.12'],
            ['technology' => $nodejs->value, 'raw_version' => ' 22.11.0 '],
        ],
        'lockfile_uploads' => [UploadedFile::fake()->createWithContent('composer.lock', lockfileFixture('composer.lock'))],
    ]));

    $service = Service::where('slug', 'shop-api')->sole();
    $response->assertSessionHasNoErrors()->assertRedirect("/admin/services/{$service->id}/edit");
    $deployment = $service->productionDeployment;
    expect($deployment->runtimes()->pluck('raw_version', 'technology')->all())->toEqual([$php->value => '8.3.12', $nodejs->value => '22.11.0'])
        ->and($deployment->lockfiles()->sole()->path)->toBe('composer.lock');
    Event::assertDispatched(DeploymentInventoryChanged::class);
});

it('offers every known version of each technology, newest first', function () {
    $this->travelTo('2026-10-23 10:00:00');
    actingAsAdmin();
    $php = syncedTechnology(Technology::Php);
    $project = Project::factory()->create();

    get("/admin/services/create?project={$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('services/create')
            ->where('defaultProjectId', $project->id)
            ->where('technologies.1.value', $php->value)
            ->where('technologies.1.versions.0', '8.5.12')
            ->where('technologies.1.versions', fn ($versions) => $versions->contains('8.3.36') && $versions->contains('8.1.34')));
});

it('accepts a version that no catalog lists', function () {
    actingAsAdmin();
    $php = Technology::Php;

    post('/admin/services', serviceForm(['runtimes' => [['technology' => $php->value, 'raw_version' => '8.3.12-1+deb.sury.org+1']]]))
        ->assertSessionHasNoErrors();

    expect(Runtime::sole()->raw_version)->toBe('8.3.12-1+deb.sury.org+1');
});

it('rejects a lockfile it cannot scan', function () {
    actingAsAdmin();

    post('/admin/services', serviceForm(['lockfile_uploads' => [UploadedFile::fake()->createWithContent('bun.lock', 'x')]]))
        ->assertSessionHasErrors(['lockfile_uploads.0' => 'Chỉ nhận composer.lock, Gemfile.lock, package-lock.json, pnpm-lock.yaml hoặc yarn.lock.']);

    expect(Service::count())->toBe(0);
});

it('takes pnpm and yarn lockfiles and shows the one not read because the other is', function () {
    actingAsAdmin();
    fakeOsv();

    post('/admin/services', serviceForm([
        'lockfile_uploads' => [
            UploadedFile::fake()->createWithContent('pnpm-lock.yaml', lockfileFixture('pnpm-lock.yaml')),
            UploadedFile::fake()->createWithContent('yarn.lock', lockfileFixture('yarn.lock')),
        ],
    ]))->assertSessionHasNoErrors();

    expect(Service::sole()->productionDeployment->lockfiles()->pluck('ecosystem', 'path')->map->value->all())->toEqual(['pnpm-lock.yaml' => 'npm', 'yarn.lock' => 'npm']);

    get('/admin/services')->assertInertia(fn (Assert $page) => $page
        ->where('services.data.0.lockfiles.0.path', 'pnpm-lock.yaml')
        ->where('services.data.0.lockfiles.0.packages', '32 package')
        ->where('services.data.0.lockfiles.1.path', 'yarn.lock')
        ->where('services.data.0.lockfiles.1.state', ['label' => 'Bỏ qua, đọc pnpm-lock.yaml', 'tone' => 'neutral'])
        ->where('services.data.0.lockfiles.1.packages', null));
});

it('rejects a version with characters a runtime version never has', function () {
    actingAsAdmin();

    post('/admin/services', serviceForm(['runtimes' => [['technology' => Technology::Php->value, 'raw_version' => '8.3; rm -rf /']]]))
        ->assertSessionHasErrors(['runtimes.0.raw_version' => 'Version chỉ gồm chữ, số và . + ~ _ -']);
});

it('rejects the same technology twice', function () {
    actingAsAdmin();
    $php = Technology::Php;

    post('/admin/services', serviceForm(['runtimes' => [
        ['technology' => $php->value, 'raw_version' => '8.3.12'],
        ['technology' => $php->value, 'raw_version' => '8.4.1'],
    ]]))->assertSessionHasErrors(['runtimes.1.technology' => 'Mỗi công nghệ chỉ chọn một lần.']);
});

it('allows the same slug in different projects but not twice in one project', function () {
    actingAsAdmin();
    $existing = Service::factory()->create(['slug' => 'api']);

    post('/admin/services', serviceForm(['project_id' => $existing->project_id, 'slug' => 'api']))->assertSessionHasErrors('slug');
    post('/admin/services', serviceForm(['slug' => 'api']))->assertSessionHasNoErrors();
});

it('fills the edit form with the production runtime versions and lockfiles', function () {
    actingAsAdmin();
    $deployment = Deployment::factory()->create();
    $runtime = Runtime::factory()->for($deployment)->create(['raw_version' => '3.3.6']);
    Lockfile::factory()->for($deployment)->create(['path' => 'Gemfile.lock']);

    get("/admin/services/{$deployment->service_id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->component('services/edit')
            ->where('service.runtimes', [['technology' => $runtime->technology->value, 'raw_version' => '3.3.6']])
            ->where('service.lockfiles.0.path', 'Gemfile.lock'));
});

it('counts the open findings of the service on the edit page, so it can link to them', function () {
    actingAsAdmin();
    $deployment = Deployment::factory()->create();
    Finding::factory()->count(2)->for($deployment)->create();
    Finding::factory()->for($deployment)->resolved()->create();
    Finding::factory()->create();

    get("/admin/services/{$deployment->service_id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('service.open_findings_count', 2));
});

it('queues an evaluation only when the saved version changed', function (string $newVersion, bool $shouldEvaluate) {
    actingAsAdmin();
    $deployment = Deployment::factory()->create();
    $runtime = Runtime::factory()->for($deployment)->create(['raw_version' => '8.3.12']);
    $service = $deployment->service;
    Event::fake([DeploymentInventoryChanged::class]);

    // The form sends files, so the update is a POST that Laravel reads as PUT.
    post("/admin/services/{$service->id}", [
        '_method' => 'put',
        ...serviceForm([
            'project_id' => $service->project_id,
            'name' => $service->name,
            'slug' => $service->slug,
            'runtimes' => [['technology' => $runtime->technology->value, 'raw_version' => $newVersion]],
        ]),
    ])->assertSessionHasNoErrors()->assertRedirect("/admin/services/{$service->id}/edit");

    Event::assertDispatchedTimes(DeploymentInventoryChanged::class, $shouldEvaluate ? 1 : 0);
})->with([
    'unchanged' => ['8.3.12', false],
    'upgraded' => ['8.3.13', true],
]);

it('removes the lockfiles marked for removal', function () {
    actingAsAdmin();
    $service = Service::factory()->create();
    app(UpdateProductionInventory::class)->handle($service, [], [
        ['path' => 'composer.lock', 'content' => lockfileFixture('composer.lock')],
        ['path' => 'Gemfile.lock', 'content' => lockfileFixture('Gemfile.lock')],
    ]);

    post("/admin/services/{$service->id}", [
        '_method' => 'put',
        ...serviceForm(['project_id' => $service->project_id, 'name' => $service->name, 'slug' => $service->slug, 'removed_lockfiles' => ['Gemfile.lock']]),
    ])->assertSessionHasNoErrors();

    expect($service->productionDeployment->lockfiles()->pluck('path')->all())->toBe(['composer.lock']);
});

it('uploads lockfiles of the same kind into subdirectories of the service', function () {
    actingAsAdmin();

    post('/admin/services', serviceForm([
        'lockfile_uploads' => [
            UploadedFile::fake()->createWithContent('package-lock.json', '{"lockfileVersion":3}'),
            UploadedFile::fake()->createWithContent('package-lock.json', '{"lockfileVersion":3}'),
            UploadedFile::fake()->createWithContent('composer.lock', '{"packages":[]}'),
        ],
        'lockfile_directories' => [' /frontend/ ', 'packages/@acme/admin', ''],
    ]))->assertSessionHasNoErrors();

    expect(Lockfile::orderBy('path')->get()->map(fn (Lockfile $lockfile): array => [$lockfile->path, $lockfile->ecosystem->value])->all())
        ->toBe([['composer.lock', 'packagist'], ['frontend/package-lock.json', 'npm'], ['packages/@acme/admin/package-lock.json', 'npm']]);
});

it('rejects an upload directory outside the service directory or with odd characters', function (string $directory) {
    actingAsAdmin();

    post('/admin/services', serviceForm([
        'lockfile_uploads' => [UploadedFile::fake()->createWithContent('composer.lock', '{}')],
        'lockfile_directories' => [$directory],
    ]))->assertSessionHasErrors(['lockfile_directories.0' => 'Thư mục chỉ gồm chữ, số và . _ @ + -, các cấp ngăn bằng "/", không có ".." (tối đa 255 ký tự).']);

    expect(Lockfile::count())->toBe(0);
})->with(['..', '../shared', 'apps/../..', './web', 'a//b', 'a\b', 'my app', "we\nb", "we\0b", str_repeat('a/', 124)]);

it('rejects two uploads at the same path, and uploading a lockfile while removing it', function () {
    actingAsAdmin();
    $service = Service::factory()->create();
    app(UpdateProductionInventory::class)->handle($service, [], [['path' => 'web/package-lock.json', 'content' => '{}']]);
    $form = ['_method' => 'put', ...serviceForm(['project_id' => $service->project_id, 'name' => $service->name, 'slug' => $service->slug])];

    post("/admin/services/{$service->id}", [
        ...$form,
        'lockfile_uploads' => [UploadedFile::fake()->createWithContent('package-lock.json', '{"a":1}'), UploadedFile::fake()->createWithContent('package-lock.json', '{"b":1}')],
        'lockfile_directories' => ['api', 'api/'],
    ])->assertSessionHasErrors(['lockfile_directories.1' => 'Đã chọn một file khác cho api/package-lock.json.']);

    post("/admin/services/{$service->id}", [
        ...$form,
        'lockfile_uploads' => [UploadedFile::fake()->createWithContent('package-lock.json', '{"a":1}')],
        'lockfile_directories' => ['web'],
        'removed_lockfiles' => ['web/package-lock.json'],
    ])->assertSessionHasErrors(['lockfile_directories.0' => 'Không vừa xóa vừa tải lên web/package-lock.json. Bỏ dấu xóa để thay file cũ.']);

    post("/admin/services/{$service->id}", [...$form, 'removed_lockfiles' => ['package-lock.json']])
        ->assertSessionHasErrors(['removed_lockfiles.0' => 'Service không có lockfile package-lock.json.']);

    post("/admin/services/{$service->id}", [...$form, 'removed_lockfiles' => [['web/package-lock.json']]])
        ->assertSessionHasErrors(['removed_lockfiles.0']);

    expect($service->productionDeployment->lockfiles()->sole())->path->toBe('web/package-lock.json')->content->toBe('{}');
});

it('replaces the uploaded lockfile at the same path', function () {
    actingAsAdmin();
    $service = Service::factory()->create();
    app(UpdateProductionInventory::class)->handle($service, [], [['path' => 'web/package-lock.json', 'content' => '{}'], ['path' => 'package-lock.json', 'content' => '{}']]);

    post("/admin/services/{$service->id}", [
        '_method' => 'put',
        ...serviceForm(['project_id' => $service->project_id, 'name' => $service->name, 'slug' => $service->slug]),
        'lockfile_uploads' => [UploadedFile::fake()->createWithContent('package-lock.json', '{"new":true}')],
        'lockfile_directories' => ['web'],
    ])->assertSessionHasNoErrors();

    expect($service->productionDeployment->lockfiles()->orderBy('path')->pluck('content', 'path')->all())->toBe(['package-lock.json' => '{}', 'web/package-lock.json' => '{"new":true}']);
});

it('shows the package count and the vulnerable packages of each lockfile', function () {
    $this->travelTo('2026-10-23 10:00:00');
    actingAsAdmin();
    fakePackagist();
    fakeRubySec();
    $scanned = Service::factory()->create(['name' => 'shop-api']);
    $unreadable = Service::factory()->create(['name' => 'shop-web']);
    $waiting = Service::factory()->create(['name' => 'shop-admin']);
    Service::factory()->create(['name' => 'shop-jobs']);
    app(UpdateProductionInventory::class)->handle($scanned, [], [['path' => 'composer.lock', 'content' => lockfileFixture('composer.lock')], ['path' => 'Gemfile.lock', 'content' => lockfileFixture('Gemfile.lock')]]);
    app(UpdateProductionInventory::class)->handle($unreadable, [], [['path' => 'package-lock.json', 'content' => 'not json']]);
    Lockfile::factory()->for(Deployment::factory()->for($waiting))->create();

    get('/admin/services')->assertInertia(fn (Assert $page) => $page
        ->component('services/index')
        ->where('services.data.0.name', 'shop-admin')
        ->where('services.data.0.lockfiles.0.state.label', 'Đang chờ quét')
        ->where('services.data.1.name', 'shop-api')
        ->where('services.data.1.lockfiles.0.path', 'Gemfile.lock')
        ->where('services.data.1.lockfiles.0.packages', '6 package')
        ->where('services.data.1.lockfiles.0.vulnerable.label', '2 có lỗ hổng')
        ->where('services.data.1.lockfiles.1.packages', '5 package')
        ->where('services.data.1.lockfiles.1.vulnerable.label', '3 có lỗ hổng')
        ->where('services.data.2.lockfiles', [])
        ->where('services.data.3.lockfiles.0.state.label', 'Không đọc được')
        ->where('services.data.3.lockfiles.0.error', 'Not valid JSON: Syntax error.'));
});

it('counts a vulnerable package for every lockfile holding it', function () {
    $this->travelTo('2026-10-23 10:00:00');
    actingAsAdmin();
    fakePackagist();
    $service = Service::factory()->create(['name' => 'shop-api']);
    app(UpdateProductionInventory::class)->handle($service, [], [
        ['path' => 'composer.lock', 'content' => lockfileFixture('composer.lock')],
        ['path' => 'admin/composer.lock', 'content' => lockfileFixture('composer.lock')],
    ]);

    get('/admin/services')->assertInertia(fn (Assert $page) => $page
        ->where('services.data.0.lockfiles.0.path', 'admin/composer.lock')
        ->where('services.data.0.lockfiles.0.vulnerable.label', '3 có lỗ hổng')
        ->where('services.data.0.lockfiles.1.path', 'composer.lock')
        ->where('services.data.0.lockfiles.1.vulnerable.label', '3 có lỗ hổng')
        ->where('services.data.0.open_findings_count', 4));
});

it('filters services by project', function () {
    actingAsAdmin();
    $service = Service::factory()->create(['name' => 'shop-api']);
    Service::factory()->create(['name' => 'rooms-api']);

    get("/admin/services?project={$service->project_id}")->assertInertia(fn (Assert $page) => $page
        ->has('services.data', 1)
        ->where('services.data.0.name', 'shop-api'));
});

it('previews how a version would be evaluated', function () {
    $this->travelTo('2026-10-23 10:00:00');
    actingAsAdmin();
    $php = syncedTechnology(Technology::Php);

    getJson("/admin/runtime-evaluation?technology={$php->value}&version=8.3.35-1%2Bdeb.sury.org%2B1")
        ->assertOk()
        ->assertJsonPath('status.label', 'Thiếu bản vá bảo mật')
        ->assertJsonPath('status.tone', 'warning')
        ->assertJson(fn ($json) => $json->where('details', fn (string $details) => str_contains($details, 'hiểu là 8.3.35, nhánh 8.3'))->etc());
});

it('lists the runtime status the form would preview, not the one stored before the catalog went stale', function () {
    $this->travelTo('2026-10-23 10:00:00');
    actingAsAdmin();
    $php = syncedTechnology(Technology::Php);
    $runtime = Runtime::factory()->create(['technology' => $php, 'raw_version' => '8.3.35', 'status' => RuntimeStatus::Ok]);
    $listedStatus = fn () => get('/admin/services')->assertInertia(fn (Assert $page) => $page->where('services.data.0.runtimes.0.name', 'PHP 8.3.35'))
        ->inertiaProps('services.data.0.runtimes.0');

    expect($listedStatus())->toMatchArray(['status' => ['label' => 'Thiếu bản vá bảo mật', 'tone' => 'warning']]);

    $this->travel(config('psm.catalog.stale_after_minutes') + 1)->minutes();

    expect($listedStatus())->toMatchArray([
        'status' => ['label' => 'Thiếu dữ liệu', 'tone' => 'unknown'],
        'reason' => 'Danh mục version PHP chưa đồng bộ hoặc đang lỗi',
    ])->and($runtime->fresh()->status)->toBe(RuntimeStatus::Ok);
});

it('deletes a service and returns to its project', function () {
    actingAsAdmin();
    $service = Service::factory()->create();

    delete("/admin/services/{$service->id}")->assertRedirect("/admin/projects/{$service->project_id}");

    assertModelMissing($service);
});

it('takes no lockfile uploads for a service that reads them from github', function () {
    actingAsAdmin();
    AccessibleRepository::factory()->create(['full_name' => 'acme/shop']);
    configureGitHubApp();

    post('/admin/services', serviceForm([
        'repository' => 'acme/shop',
        'lockfile_uploads' => [UploadedFile::fake()->createWithContent('composer.lock', lockfileFixture('composer.lock'))],
    ]))->assertSessionHasErrors(['lockfile_uploads' => 'Service lấy lockfile từ GitHub thì không tải lên hay xóa tay được.']);
});

it('creates a service that reads its lockfiles from a github repository right away', function () {
    actingAsAdmin();
    Queue::fake([SyncServiceLockfilesJob::class]);
    AccessibleRepository::factory()->create(['full_name' => 'acme/shop']);
    Http::fake(['https://api.github.com/repos/acme/shop/branches/release%2F2026-10' => Http::response(['name' => 'release/2026-10'])]);
    fakeGitHub();

    post('/admin/services', serviceForm(['repository' => 'acme/shop', 'repository_branch' => 'release/2026-10', 'repository_path' => '/apps/api']))->assertSessionHasNoErrors();

    $service = Service::sole();
    expect($service)->repository->toBe('acme/shop')->repository_branch->toBe('release/2026-10');
    Queue::assertPushed(SyncServiceLockfilesJob::class, fn (SyncServiceLockfilesJob $job): bool => $job->service->is($service));
});

it('only takes a repository the github app may read', function () {
    actingAsAdmin();
    AccessibleRepository::factory()->create(['full_name' => 'acme/shop']);

    post('/admin/services', serviceForm(['repository' => 'acme/secret']))
        ->assertSessionHasErrors(['repository' => 'Repository không có trong danh sách GitHub App được đọc. Làm mới danh sách rồi chọn lại.']);
});

it('keeps a repository that left the list until another one is picked', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    $service = Service::factory()->syncingLockfiles('acme/gone', 'main')->create();

    post("/admin/services/{$service->id}", serviceForm([
        'project_id' => $service->project_id,
        'name' => 'shop-api (renamed)',
        'slug' => $service->slug,
        'repository' => 'acme/gone',
        'repository_branch' => 'main',
        '_method' => 'put',
    ]))->assertSessionHasNoErrors();

    expect($service->fresh()->repository)->toBe('acme/gone');
});

it('checks on github that a branch typed by hand exists', function () {
    actingAsAdmin();
    AccessibleRepository::factory()->create(['full_name' => 'acme/shop']);
    Http::fake(['https://api.github.com/repos/acme/shop/branches/*' => Http::response(['message' => 'Branch not found'], 404)]);
    fakeGitHub();

    post('/admin/services', serviceForm(['repository' => 'acme/shop', 'repository_branch' => 'gone']))
        ->assertSessionHasErrors(['repository_branch' => 'Không thấy branch gone trong acme/shop.']);

    expect(Service::count())->toBe(0);
});

it('cannot save a new branch while github is down', function () {
    actingAsAdmin();
    Sleep::fake();
    AccessibleRepository::factory()->create(['full_name' => 'acme/shop']);
    Http::fake(['https://api.github.com/repos/*' => Http::response('Service Unavailable', 503)]);
    fakeGitHub();

    post('/admin/services', serviceForm(['repository' => 'acme/shop', 'repository_branch' => 'main']))->assertSessionHasErrors('repository_branch');

    expect(session('errors')->first('repository_branch'))->toStartWith('Không kiểm tra được branch trên GitHub, thử lại sau:');
});

it('rejects a branch name git would not accept', function (string $branch) {
    actingAsAdmin();
    Http::preventStrayRequests();
    AccessibleRepository::factory()->create(['full_name' => 'acme/shop']);

    post('/admin/services', serviceForm(['repository' => 'acme/shop', 'repository_branch' => $branch]))
        ->assertSessionHasErrors(['repository_branch' => 'Tên branch không hợp lệ.']);
})->with(['main..dev', '-main', 'feature branch']);

it('reads the lockfiles again when the service moves to another directory', function () {
    actingAsAdmin();
    Queue::fake([SyncServiceLockfilesJob::class]);
    $service = Service::factory()->syncingLockfiles('acme/shop')->create([
        'repository_path' => '/',
        'lockfiles_synced_at' => '2026-10-24 06:30:00',
        'lockfiles_commit_sha' => GITHUB_COMMIT,
    ]);
    $form = serviceForm(['project_id' => $service->project_id, 'name' => $service->name, 'slug' => $service->slug, 'repository' => 'acme/shop', '_method' => 'put']);

    post("/admin/services/{$service->id}", $form)->assertSessionHasNoErrors();
    Queue::assertNothingPushed();

    post("/admin/services/{$service->id}", [...$form, 'repository_path' => '/apps/api'])->assertSessionHasNoErrors();
    Queue::assertPushed(SyncServiceLockfilesJob::class, 1);
    expect($service->fresh()->lockfiles_commit_sha)->toBe(GITHUB_COMMIT);
});

it('saves the paths to leave out of the lockfile search and reads the lockfiles again', function () {
    actingAsAdmin();
    Queue::fake([SyncServiceLockfilesJob::class]);
    $service = Service::factory()->syncingLockfiles('acme/shop')->create(['repository_path' => '/']);
    $form = serviceForm(['project_id' => $service->project_id, 'name' => $service->name, 'slug' => $service->slug, 'repository' => 'acme/shop', '_method' => 'put']);

    post("/admin/services/{$service->id}", [...$form, 'lockfile_excludes' => "legacy/\n\n /tools/old/package-lock.json \nlegacy"])->assertSessionHasNoErrors();

    expect($service->fresh()->lockfile_excludes)->toBe(['legacy', 'tools/old/package-lock.json']);
    Queue::assertPushed(SyncServiceLockfilesJob::class, 1);

    get("/admin/services/{$service->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('service.lockfile_excludes', "legacy\ntools/old/package-lock.json")
        ->where('lockfileSkippedDirectories', config('psm.github.lockfile_search.skip_directories')));

    post("/admin/services/{$service->id}", [...$form, 'lockfile_excludes' => ''])->assertSessionHasNoErrors();
    expect($service->fresh()->lockfile_excludes)->toBeNull();
});

it('ignores the paths to leave out of a service that uploads its lockfiles', function () {
    actingAsAdmin();

    post('/admin/services', serviceForm(['lockfile_excludes' => '../old']))->assertSessionHasNoErrors();

    expect(Service::sole()->lockfile_excludes)->toBeNull();
});

it('rejects a path to leave out that climbs out of the service directory', function () {
    actingAsAdmin();
    $service = Service::factory()->syncingLockfiles('acme/shop')->create();

    post("/admin/services/{$service->id}", [
        '_method' => 'put',
        ...serviceForm(['project_id' => $service->project_id, 'name' => $service->name, 'slug' => $service->slug, 'repository' => 'acme/shop', 'lockfile_excludes' => "legacy\n../other"]),
    ])->assertSessionHasErrors(['lockfile_excludes' => '"../other" không hợp lệ: mỗi dòng là một đường dẫn trong thư mục service, chỉ gồm chữ, số và . _ @ + -, không có "..".']);
});

it('forgets the last sync when the service moves to another repository', function () {
    actingAsAdmin();
    Queue::fake([SyncServiceLockfilesJob::class]);
    AccessibleRepository::factory()->create(['full_name' => 'acme/storefront']);
    $service = Service::factory()->syncingLockfiles('acme/shop')->create([
        'lockfiles_synced_at' => '2026-10-24 06:30:00',
        'lockfiles_commit_sha' => GITHUB_COMMIT,
        'lockfiles_sync_error' => 'Không thấy thư mục /apps/api tại commit 3f78685.',
    ]);

    post("/admin/services/{$service->id}", serviceForm([
        'project_id' => $service->project_id,
        'name' => $service->name,
        'slug' => $service->slug,
        'repository' => 'acme/storefront',
        '_method' => 'put',
    ]))->assertSessionHasNoErrors();

    expect($service->fresh())->lockfiles_synced_at->toBeNull()->lockfiles_commit_sha->toBeNull()->lockfiles_sync_error->toBeNull();
    Queue::assertPushed(SyncServiceLockfilesJob::class);
});

it('forgets the branch when the service stops reading lockfiles from github', function () {
    actingAsAdmin();
    $service = Service::factory()->syncingLockfiles('acme/shop', 'main')->create();

    post("/admin/services/{$service->id}", serviceForm([
        'project_id' => $service->project_id,
        'name' => $service->name,
        'slug' => $service->slug,
        'repository' => null,
        'repository_branch' => 'main',
        'lockfile_uploads' => [UploadedFile::fake()->createWithContent('composer.lock', lockfileFixture('composer.lock'))],
        '_method' => 'put',
    ]))->assertSessionHasNoErrors();

    expect($service->fresh())->repository->toBeNull()->repository_branch->toBeNull();
});

it('shows the repository list, the last sync and where each lockfile came from', function () {
    actingAsAdmin();
    configureGitHubApp();
    $this->travelTo('2026-10-25 09:00:00');
    AccessibleRepository::factory()->create(['full_name' => 'acme/shop', 'default_branch' => 'develop', 'is_private' => true]);
    $service = Service::factory()->syncingLockfiles('acme/shop')->create([
        'lockfiles_synced_at' => '2026-10-24 06:30:00',
        'lockfiles_commit_sha' => GITHUB_COMMIT,
    ]);
    $deployment = Deployment::factory()->for($service)->create();
    Lockfile::factory()->for($deployment)->fromGitHub(GITHUB_COMMIT)->create(['path' => 'composer.lock']);

    get("/admin/services/{$service->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('repositories', [['value' => 'acme/shop', 'label' => 'acme/shop', 'description' => 'develop · private', 'default_branch' => 'develop']])
        ->where('repositoriesSyncedAt', '25/10/2026 16:00 GMT+7')
        ->where('gitHubConfigured', true)
        ->where('service.repository', 'acme/shop')
        ->where('service.lockfile_sync.status.label', 'Đã đồng bộ')
        ->where('service.lockfile_sync.commit_href', 'https://github.com/acme/shop/commit/'.GITHUB_COMMIT)
        ->where('service.lockfiles.0.source', 'github')
        ->where('service.lockfiles.0.commit', '3f78685'));
});

it('queues reading the lockfiles of a service on request', function () {
    actingAsAdmin();
    Queue::fake([SyncServiceLockfilesJob::class]);

    post('/admin/services/'.Service::factory()->syncingLockfiles()->create()->id.'/lockfile-sync')->assertRedirect();
    post('/admin/services/'.Service::factory()->create()->id.'/lockfile-sync')->assertNotFound();

    Queue::assertPushed(SyncServiceLockfilesJob::class, 1);
});

it('saves the operating system of the production deployment, shows it on the edit form and queues an evaluation when only it changed', function (?OperatingSystem $saved, ?string $posted, bool $shouldEvaluate) {
    actingAsAdmin();
    $deployment = Deployment::factory()->create(['os' => $saved]);
    $runtime = Runtime::factory()->for($deployment)->create(['raw_version' => '8.4.27']);
    $service = $deployment->service;
    Event::fake([DeploymentInventoryChanged::class]);

    post("/admin/services/{$service->id}", [
        '_method' => 'put',
        ...serviceForm([
            'project_id' => $service->project_id,
            'name' => $service->name,
            'slug' => $service->slug,
            'os' => $posted,
            'runtimes' => [['technology' => $runtime->technology->value, 'raw_version' => '8.4.27']],
        ]),
    ])->assertSessionHasNoErrors();

    expect($deployment->refresh()->os?->value)->toBe($posted === '' ? null : $posted);
    Event::assertDispatchedTimes(DeploymentInventoryChanged::class, $shouldEvaluate ? 1 : 0);
    get("/admin/services/{$service->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('service.os', $posted === '' ? null : $posted)->where('operatingSystems.1', ['value' => 'windows', 'label' => 'Windows']));
})->with([
    'linux to windows' => [OperatingSystem::Linux, 'windows', true],
    'unknown to linux' => [null, 'linux', true],
    'windows to unknown' => [OperatingSystem::Windows, '', true],
    'unchanged' => [OperatingSystem::Linux, 'linux', false],
]);

it('rejects an operating system it does not know', function () {
    actingAsAdmin();

    post('/admin/services', serviceForm(['os' => 'freebsd']))->assertSessionHasErrors('os');
});

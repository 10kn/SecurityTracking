<?php

use App\Actions\UpdateProductionInventory;
use App\Enums\RuntimeStatus;
use App\Enums\Technology;
use App\Events\DeploymentInventoryChanged;
use App\Jobs\SyncProjectLockfilesJob;
use App\Models\Deployment;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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

    post('/admin/services', serviceForm(['lockfile_uploads' => [UploadedFile::fake()->createWithContent('yarn.lock', 'x')]]))
        ->assertSessionHasErrors(['lockfile_uploads.0' => 'Chỉ nhận composer.lock, Gemfile.lock hoặc package-lock.json.']);

    expect(Service::count())->toBe(0);
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
        ['name' => 'composer.lock', 'content' => lockfileFixture('composer.lock')],
        ['name' => 'Gemfile.lock', 'content' => lockfileFixture('Gemfile.lock')],
    ]);

    post("/admin/services/{$service->id}", [
        '_method' => 'put',
        ...serviceForm(['project_id' => $service->project_id, 'name' => $service->name, 'slug' => $service->slug, 'removed_lockfiles' => ['Gemfile.lock']]),
    ])->assertSessionHasNoErrors();

    expect($service->productionDeployment->lockfiles()->pluck('path')->all())->toBe(['composer.lock']);
});

it('shows the package count and the vulnerable packages of each lockfile', function () {
    $this->travelTo('2026-10-23 10:00:00');
    actingAsAdmin();
    fakePackagist();
    fakeOsv();
    $scanned = Service::factory()->create(['name' => 'shop-api']);
    $unreadable = Service::factory()->create(['name' => 'shop-web']);
    $waiting = Service::factory()->create(['name' => 'shop-admin']);
    Service::factory()->create(['name' => 'shop-jobs']);
    app(UpdateProductionInventory::class)->handle($scanned, [], [['name' => 'composer.lock', 'content' => lockfileFixture('composer.lock')], ['name' => 'Gemfile.lock', 'content' => lockfileFixture('Gemfile.lock')]]);
    app(UpdateProductionInventory::class)->handle($unreadable, [], [['name' => 'package-lock.json', 'content' => 'not json']]);
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

it('takes no lockfile uploads for a project that reads them from github', function () {
    actingAsAdmin();
    $project = Project::factory()->syncingLockfiles()->create();

    post('/admin/services', serviceForm([
        'project_id' => $project->id,
        'lockfile_uploads' => [UploadedFile::fake()->createWithContent('composer.lock', lockfileFixture('composer.lock'))],
    ]))->assertSessionHasErrors(['lockfile_uploads' => 'Project này lấy lockfile từ GitHub, không tải lên hay xóa tay được.']);
});

it('reads the lockfiles of a new service of a project that syncs them', function () {
    actingAsAdmin();
    Queue::fake([SyncProjectLockfilesJob::class]);
    $project = Project::factory()->syncingLockfiles()->create();

    post('/admin/services', serviceForm(['project_id' => $project->id, 'repository_path' => '/apps/api']))->assertSessionHasNoErrors();

    Queue::assertPushed(SyncProjectLockfilesJob::class, fn (SyncProjectLockfilesJob $job): bool => $job->project->is($project));
});

it('reads the lockfiles again when a service moves to another directory', function () {
    actingAsAdmin();
    Queue::fake([SyncProjectLockfilesJob::class]);
    $service = Service::factory()->for(Project::factory()->syncingLockfiles())->create(['repository_path' => '/']);
    $form = serviceForm(['project_id' => $service->project_id, 'name' => $service->name, 'slug' => $service->slug, '_method' => 'put']);

    post("/admin/services/{$service->id}", [...$form, 'repository_path' => '/'])->assertSessionHasNoErrors();
    Queue::assertNothingPushed();

    post("/admin/services/{$service->id}", [...$form, 'repository_path' => '/apps/api'])->assertSessionHasNoErrors();
    Queue::assertPushed(SyncProjectLockfilesJob::class);
});

it('shows where each lockfile came from', function () {
    actingAsAdmin();
    $project = Project::factory()->syncingLockfiles()->create();
    $deployment = Deployment::factory()->for(Service::factory()->for($project))->create();
    Lockfile::factory()->for($deployment)->fromGitHub(GITHUB_COMMIT)->create(['path' => 'composer.lock']);

    get("/admin/services/{$deployment->service_id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('service.lockfiles.0.source', 'github')
        ->where('service.lockfiles.0.commit', '3f78685')
        ->where('lockfileSyncProjectIds', [$project->id]));
});

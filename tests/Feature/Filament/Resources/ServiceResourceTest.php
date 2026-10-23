<?php

use App\Enums\Language;
use App\Events\DeploymentInventoryChanged;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

it('creates a service with its production runtimes and lockfile and queues an evaluation', function () {
    actingAsAdmin();
    $project = Project::factory()->create();
    Event::fake([DeploymentInventoryChanged::class]);
    $lockfile = UploadedFile::fake()->createWithContent('composer.lock', file_get_contents(base_path('tests/Fixtures/lockfiles/composer.lock')));

    Livewire::test(CreateService::class)
        ->fillForm([
            'project_id' => $project->id,
            'name' => 'shop-api',
            'runtime_versions' => ['php' => '8.3.12', 'nodejs' => '22.11.0'],
            'lockfile_uploads' => [$lockfile],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deployment = Service::where('slug', 'shop-api')->sole()->productionDeployment;
    expect($deployment->runtimes()->pluck('raw_version', 'language')->all())->toEqual(['php' => '8.3.12', 'nodejs' => '22.11.0'])
        ->and($deployment->lockfiles()->sole()->path)->toBe('composer.lock');
    Event::assertDispatched(DeploymentInventoryChanged::class);
});

it('prefills the project from the query string', function () {
    actingAsAdmin();
    $project = Project::factory()->create();

    Livewire::withQueryParams(['project' => $project->id])
        ->test(CreateService::class)
        ->assertSchemaStateSet(['project_id' => $project->id]);
});

it('rejects a lockfile it cannot scan', function () {
    actingAsAdmin();

    Livewire::test(CreateService::class)
        ->fillForm([
            'project_id' => Project::factory()->create()->id,
            'name' => 'shop-web',
            'lockfile_uploads' => [UploadedFile::fake()->createWithContent('yarn.lock', 'x')],
        ])
        ->call('create')
        ->assertHasFormErrors(['lockfile_uploads']);

    expect(Service::count())->toBe(0);
});

it('rejects a version with characters a runtime version never has', function () {
    actingAsAdmin();

    Livewire::test(CreateService::class)
        ->fillForm([
            'project_id' => Project::factory()->create()->id,
            'name' => 'shop-api',
            'runtime_versions' => ['php' => '8.3; rm -rf /'],
        ])
        ->call('create')
        ->assertHasFormErrors(['runtime_versions.php' => 'regex']);
});

it('allows the same slug in different projects but not twice in one project', function () {
    actingAsAdmin();
    $existing = Service::factory()->create(['slug' => 'api']);

    Livewire::test(CreateService::class)
        ->fillForm(['project_id' => $existing->project_id, 'name' => 'api'])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);

    Livewire::test(CreateService::class)
        ->fillForm(['project_id' => Project::factory()->create()->id, 'name' => 'api'])
        ->call('create')
        ->assertHasNoFormErrors();
});

it('fills the edit form with the production runtime versions', function () {
    actingAsAdmin();
    $deployment = Deployment::factory()->create();
    Runtime::factory()->for($deployment)->create(['language' => Language::Ruby, 'raw_version' => '3.3.6']);

    Livewire::test(EditService::class, ['record' => $deployment->service->getRouteKey()])
        ->assertSchemaStateSet(['runtime_versions.ruby' => '3.3.6']);
});

it('queues an evaluation only when the saved version changed', function (string $newVersion, bool $shouldEvaluate) {
    actingAsAdmin();
    $deployment = Deployment::factory()->create();
    Runtime::factory()->for($deployment)->create(['raw_version' => '8.3.12']);
    Event::fake([DeploymentInventoryChanged::class]);

    Livewire::test(EditService::class, ['record' => $deployment->service->getRouteKey()])
        ->fillForm(['runtime_versions' => ['php' => $newVersion]])
        ->call('save')
        ->assertHasNoFormErrors();

    Event::assertDispatchedTimes(DeploymentInventoryChanged::class, $shouldEvaluate ? 1 : 0);
})->with([
    'unchanged' => ['8.3.12', false],
    'upgraded' => ['8.3.13', true],
]);

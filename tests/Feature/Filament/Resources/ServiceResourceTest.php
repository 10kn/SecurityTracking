<?php

use App\Actions\UpdateProductionInventory;
use App\Events\DeploymentInventoryChanged;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Models\Deployment;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\Technology;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

it('creates a service with its production runtimes and lockfile and queues an evaluation', function () {
    actingAsAdmin();
    $project = Project::factory()->create();
    $php = Technology::factory()->php()->create();
    $nodejs = Technology::factory()->nodejs()->create();
    Event::fake([DeploymentInventoryChanged::class]);
    $undoRepeaterFake = Repeater::fake();
    $lockfile = UploadedFile::fake()->createWithContent('composer.lock', file_get_contents(base_path('tests/Fixtures/lockfiles/composer.lock')));

    Livewire::test(CreateService::class)
        ->fillForm([
            'project_id' => $project->id,
            'name' => 'shop-api',
            'runtimes' => [
                ['technology_id' => $php->id, 'raw_version' => '8.3.12'],
                ['technology_id' => $nodejs->id, 'raw_version' => '22.11.0'],
            ],
            'lockfile_uploads' => [$lockfile],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deployment = Service::where('slug', 'shop-api')->sole()->productionDeployment;
    expect($deployment->runtimes()->pluck('raw_version', 'technology_id')->all())->toEqual([$php->id => '8.3.12', $nodejs->id => '22.11.0'])
        ->and($deployment->lockfiles()->sole()->path)->toBe('composer.lock');
    Event::assertDispatched(DeploymentInventoryChanged::class);

    $undoRepeaterFake();
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
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreateService::class)
        ->fillForm([
            'project_id' => Project::factory()->create()->id,
            'name' => 'shop-api',
            'runtimes' => [['technology_id' => Technology::factory()->php()->create()->id, 'raw_version' => '8.3; rm -rf /']],
        ])
        ->call('create')
        ->assertHasFormErrors(['runtimes.0.raw_version' => 'regex']);

    $undoRepeaterFake();
});

it('rejects the same technology twice', function () {
    actingAsAdmin();
    $undoRepeaterFake = Repeater::fake();
    $php = Technology::factory()->php()->create();

    Livewire::test(CreateService::class)
        ->fillForm([
            'project_id' => Project::factory()->create()->id,
            'name' => 'shop-api',
            'runtimes' => [
                ['technology_id' => $php->id, 'raw_version' => '8.3.12'],
                ['technology_id' => $php->id, 'raw_version' => '8.4.1'],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['runtimes.1.technology_id']);

    $undoRepeaterFake();
});

it('suggests the versions of the chosen technology and previews the evaluation', function () {
    $this->travelTo('2026-10-23 10:00:00');
    actingAsAdmin();
    $undoRepeaterFake = Repeater::fake();
    $php = syncedTechnology('php');

    Livewire::test(CreateService::class)
        ->fillForm(['runtimes' => [['technology_id' => $php->id, 'raw_version' => '8.3.35-1+deb.sury.org+1']]])
        ->assertFormFieldExists('runtimes.0.raw_version', checkFieldUsing: function (Select $field): bool {
            expect($field->getOptions())->toHaveKey('8.3.36')->not->toHaveKey('8.1.34');

            return true;
        })
        ->assertSeeText('Thiếu bản vá bảo mật')
        ->assertSeeText('hiểu là 8.3.35, nhánh 8.3');

    $undoRepeaterFake();
});

it('filters the catalog versions while typing and offers the typed text as a new version', function () {
    $this->travelTo('2026-10-23 10:00:00');
    actingAsAdmin();
    $undoRepeaterFake = Repeater::fake();
    $php = syncedTechnology('php');

    Livewire::test(CreateService::class)
        ->fillForm(['runtimes' => [['technology_id' => $php->id]]])
        ->assertFormFieldExists('runtimes.0.raw_version', checkFieldUsing: function (Select $field): bool {
            expect(array_keys($field->getSearchResults('8.3.36')))->toBe(['8.3.36'])
                ->and(array_keys($field->getSearchResults('8.3.35-1+deb')))->toBe(['8.3.35-1+deb'])
                ->and($field->getSearchResults('8.3'))->toHaveKey('8.3.36')->not->toHaveKey('8.4.1')
                ->and($field->getSearchResults('8.3; rm -rf /'))->toBeEmpty();

            return true;
        });

    $undoRepeaterFake();
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
    $undoRepeaterFake = Repeater::fake();
    $deployment = Deployment::factory()->create();
    $runtime = Runtime::factory()->for($deployment)->create(['raw_version' => '3.3.6']);

    Livewire::test(EditService::class, ['record' => $deployment->service->getRouteKey()])
        ->assertSchemaStateSet(['runtimes' => [['technology_id' => $runtime->technology_id, 'raw_version' => '3.3.6']]]);

    $undoRepeaterFake();
});

it('queues an evaluation only when the saved version changed', function (string $newVersion, bool $shouldEvaluate) {
    actingAsAdmin();
    $undoRepeaterFake = Repeater::fake();
    $deployment = Deployment::factory()->create();
    $runtime = Runtime::factory()->for($deployment)->create(['raw_version' => '8.3.12']);
    Event::fake([DeploymentInventoryChanged::class]);

    Livewire::test(EditService::class, ['record' => $deployment->service->getRouteKey()])
        ->fillForm(['runtimes' => [['technology_id' => $runtime->technology_id, 'raw_version' => $newVersion]]])
        ->call('save')
        ->assertHasNoFormErrors();

    Event::assertDispatchedTimes(DeploymentInventoryChanged::class, $shouldEvaluate ? 1 : 0);

    $undoRepeaterFake();
})->with([
    'unchanged' => ['8.3.12', false],
    'upgraded' => ['8.3.13', true],
]);

it('shows the package count and the vulnerable packages of each lockfile', function () {
    $this->travelTo('2026-10-23 10:00:00');
    actingAsAdmin();
    fakeOsv();
    $scanned = Service::factory()->create(['name' => 'shop-api']);
    $unreadable = Service::factory()->create(['name' => 'shop-web']);
    $waiting = Service::factory()->create(['name' => 'shop-admin']);
    $missing = Service::factory()->create(['name' => 'shop-jobs']);
    app(UpdateProductionInventory::class)->handle($scanned, [], [['name' => 'composer.lock', 'content' => lockfileFixture('composer.lock')], ['name' => 'Gemfile.lock', 'content' => lockfileFixture('Gemfile.lock')]]);
    app(UpdateProductionInventory::class)->handle($unreadable, [], [['name' => 'package-lock.json', 'content' => 'not json']]);
    Lockfile::factory()->for(Deployment::factory()->for($waiting))->create();

    Livewire::test(ListServices::class)
        ->assertCanSeeTableRecords([$scanned, $unreadable, $waiting, $missing])
        ->assertSeeText('5 package')
        ->assertSeeText('3 có lỗ hổng')
        ->assertSeeText('6 package')
        ->assertSeeText('2 có lỗ hổng')
        ->assertSeeText('Không đọc được')
        ->assertSeeHtml('title="Not valid JSON: Syntax error."')
        ->assertSeeText('Đang chờ quét')
        ->assertSeeText('Chưa có lockfile');
});

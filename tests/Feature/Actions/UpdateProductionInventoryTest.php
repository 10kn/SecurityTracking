<?php

use App\Actions\UpdateProductionInventory;
use App\Enums\Ecosystem;
use App\Enums\Environment;
use App\Enums\RuntimeStatus;
use App\Enums\Technology;
use App\Events\DeploymentInventoryChanged;
use App\Models\Deployment;
use App\Models\Lockfile;
use App\Models\Runtime;
use App\Models\Service;
use Illuminate\Support\Facades\Event;

it('creates the production deployment with one unknown runtime per entered technology', function () {
    $service = Service::factory()->create();
    $php = Technology::Php;
    $ruby = Technology::Ruby;
    $nodejs = Technology::Nodejs;
    Event::fake([DeploymentInventoryChanged::class]);

    $deployment = app(UpdateProductionInventory::class)->handle($service, [$php->value => ' 8.3.12 ', $ruby->value => '', $nodejs->value => 'v22.11.0']);

    expect($deployment->environment)->toBe(Environment::Production)
        ->and($deployment->runtimes()->orderBy('raw_version')->get()->map(fn (Runtime $runtime): array => [$runtime->technology, $runtime->raw_version, $runtime->status])->all())
        ->toBe([[$php, '8.3.12', RuntimeStatus::Unknown], [$nodejs, 'v22.11.0', RuntimeStatus::Unknown]]);
    Event::assertDispatched(DeploymentInventoryChanged::class, fn (DeploymentInventoryChanged $event): bool => $event->deployment->is($deployment));
});

it('does not dispatch a re-evaluation when the same inventory is saved again', function () {
    $service = Service::factory()->create();
    $php = Technology::Php;
    $lockfile = ['name' => 'composer.lock', 'content' => '{"packages": []}'];
    app(UpdateProductionInventory::class)->handle($service, [$php->value => '8.3.12'], [$lockfile]);
    Event::fake([DeploymentInventoryChanged::class]);

    app(UpdateProductionInventory::class)->handle($service, [$php->value => '8.3.12'], [$lockfile]);

    Event::assertNotDispatched(DeploymentInventoryChanged::class);
});

it('resets an evaluated runtime to unknown when its version changes', function () {
    $deployment = Deployment::factory()->create();
    $runtime = Runtime::factory()->for($deployment)->create(['raw_version' => '8.3.11', 'version' => '8.3.11', 'status' => RuntimeStatus::Ok, 'evaluated_at' => now()]);
    Event::fake([DeploymentInventoryChanged::class]);

    app(UpdateProductionInventory::class)->handle($deployment->service, [$runtime->technology->value => '8.3.12']);

    expect($runtime->refresh())
        ->raw_version->toBe('8.3.12')
        ->version->toBeNull()
        ->status->toBe(RuntimeStatus::Unknown)
        ->evaluated_at->toBeNull();
    Event::assertDispatched(DeploymentInventoryChanged::class);
});

it('removes a runtime whose version is cleared', function () {
    $deployment = Deployment::factory()->create();
    $runtime = Runtime::factory()->for($deployment)->create();

    app(UpdateProductionInventory::class)->handle($deployment->service, [$runtime->technology->value => '']);

    expect($deployment->runtimes()->count())->toBe(0);
});

it('removes a runtime whose technology the admin took out of the list', function () {
    $deployment = Deployment::factory()->create();
    $kept = Runtime::factory()->for($deployment)->create(['technology' => Technology::Php]);
    Runtime::factory()->for($deployment)->create(['technology' => Technology::Ruby]);

    app(UpdateProductionInventory::class)->handle($deployment->service, [$kept->technology->value => $kept->raw_version]);

    expect($deployment->runtimes()->pluck('id')->all())->toBe([$kept->id]);
});

it('replaces a lockfile with the same name when its content changes', function () {
    $deployment = Deployment::factory()->create();
    $lockfile = Lockfile::factory()->for($deployment)->create(['path' => 'composer.lock', 'uploaded_at' => now()->subDay()]);

    app(UpdateProductionInventory::class)->handle($deployment->service, [], [['name' => 'composer.lock', 'content' => '{"packages": [1]}']]);

    expect($lockfile->refresh())
        ->content->toBe('{"packages": [1]}')
        ->content_hash->toBe(hash('sha256', '{"packages": [1]}'))
        ->uploaded_at->isToday()->toBeTrue();
});

it('detects the ecosystem from the lockfile name', function (string $name, Ecosystem $ecosystem) {
    $service = Service::factory()->create();

    $deployment = app(UpdateProductionInventory::class)->handle($service, [], [['name' => $name, 'content' => 'x']]);

    expect($deployment->lockfiles()->sole()->ecosystem)->toBe($ecosystem);
})->with([
    'composer' => ['composer.lock', Ecosystem::Packagist],
    'bundler' => ['Gemfile.lock', Ecosystem::Rubygems],
    'npm' => ['package-lock.json', Ecosystem::Npm],
]);

it('deletes the lockfiles the admin removed', function () {
    $deployment = Deployment::factory()->create();
    Lockfile::factory()->for($deployment)->create(['path' => 'composer.lock']);
    Event::fake([DeploymentInventoryChanged::class]);

    app(UpdateProductionInventory::class)->handle($deployment->service, [], [], ['composer.lock']);

    expect($deployment->lockfiles()->count())->toBe(0);
    Event::assertDispatched(DeploymentInventoryChanged::class);
});

it('rejects an unsupported lockfile name', function () {
    $service = Service::factory()->create();

    expect(fn () => app(UpdateProductionInventory::class)->handle($service, [], [['name' => 'yarn.lock', 'content' => 'x']]))
        ->toThrow(InvalidArgumentException::class);
});

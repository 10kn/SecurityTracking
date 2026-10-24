<?php

use App\Enums\ReleaseSource;
use App\Filament\Resources\Technologies\Pages\CreateTechnology;
use App\Filament\Resources\Technologies\Pages\EditTechnology;
use App\Filament\Resources\Technologies\Pages\ListTechnologies;
use App\Jobs\SyncTechnologyCatalogJob;
use App\Models\Runtime;
use App\Models\Technology;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('adds a technology from an endoflife.date product and syncs its catalog right away', function () {
    actingAsAdmin();
    fakeCatalogSources();
    Queue::fake([SyncTechnologyCatalogJob::class]);

    Livewire::test(CreateTechnology::class)
        ->fillForm(['endoflife_product' => 'python'])
        ->assertSchemaStateSet(['name' => 'Python', 'slug' => 'python'])
        ->call('create')
        ->assertHasNoFormErrors();

    $python = Technology::where('slug', 'python')->sole();
    expect($python->release_source)->toBe(ReleaseSource::Endoflife);
    Queue::assertPushed(SyncTechnologyCatalogJob::class, fn (SyncTechnologyCatalogJob $job): bool => $job->technology->is($python));
});

it('rejects a product endoflife.date does not know', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://endoflife.date/api/v1/products/*' => Http::response(['message' => 'Not Found'], 404)]);

    Livewire::test(CreateTechnology::class)
        ->fillForm(['endoflife_product' => 'not-a-product', 'name' => 'Nope', 'slug' => 'nope'])
        ->call('create')
        ->assertHasFormErrors(['endoflife_product']);

    expect(Technology::count())->toBe(0);
});

it('lists technologies with the health of their catalog', function () {
    actingAsAdmin();
    $php = syncedTechnology('php');
    $neverSynced = Technology::factory()->create(['name' => 'Go']);

    Livewire::test(ListTechnologies::class)
        ->assertCanSeeTableRecords([$php, $neverSynced])
        ->assertSeeText('Đã đồng bộ')
        ->assertSeeText('Chưa đồng bộ');
});

it('queues a sync from the list', function () {
    actingAsAdmin();
    Queue::fake([SyncTechnologyCatalogJob::class]);
    $technology = Technology::factory()->create();

    Livewire::test(ListTechnologies::class)
        ->callAction(TestAction::make('sync')->table($technology));

    Queue::assertPushed(SyncTechnologyCatalogJob::class);
});

it('disables deleting a technology that services use', function () {
    actingAsAdmin();
    $runtime = Runtime::factory()->create();

    Livewire::test(EditTechnology::class, ['record' => $runtime->technology->getRouteKey()])
        ->assertActionDisabled(DeleteAction::class);
});

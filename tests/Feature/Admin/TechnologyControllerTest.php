<?php

use App\Enums\ReleaseSource;
use App\Jobs\SyncTechnologyCatalogJob;
use App\Models\Runtime;
use App\Models\Technology;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\assertModelExists;
use function Pest\Laravel\assertModelMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

it('adds a technology from an endoflife.date product and syncs its catalog right away', function () {
    actingAsAdmin();
    fakeCatalogSources();
    Queue::fake([SyncTechnologyCatalogJob::class]);

    post('/admin/technologies', ['endoflife_product' => 'python', 'name' => 'Python', 'slug' => 'python', 'release_source' => 'endoflife'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/technologies');

    $python = Technology::where('slug', 'python')->sole();
    expect($python->release_source)->toBe(ReleaseSource::Endoflife);
    Queue::assertPushed(SyncTechnologyCatalogJob::class, fn (SyncTechnologyCatalogJob $job): bool => $job->technology->is($python));
});

it('suggests the display name endoflife.date gives a product', function () {
    actingAsAdmin();
    fakeCatalogSources();

    getJson('/admin/endoflife-products/python')->assertExactJson(['label' => 'Python']);
    getJson('/admin/endoflife-products/Not%20a%20product')->assertExactJson(['label' => null]);
});

it('rejects a product endoflife.date does not know', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://endoflife.date/api/v1/products/*' => Http::response(['message' => 'Not Found'], 404)]);

    post('/admin/technologies', ['endoflife_product' => 'not-a-product', 'name' => 'Nope', 'slug' => 'nope', 'release_source' => 'endoflife'])
        ->assertSessionHasErrors(['endoflife_product' => 'endoflife.date không có sản phẩm "not-a-product".']);

    expect(Technology::count())->toBe(0);
});

it('lists technologies with the health of their catalog', function () {
    actingAsAdmin();
    syncedTechnology('php');
    Technology::factory()->create(['name' => 'Go', 'slug' => 'go', 'endoflife_product' => 'go']);

    get('/admin/technologies')->assertInertia(fn (Assert $page) => $page
        ->component('technologies/index')
        ->where('technologies.data.0.name', 'Go')
        ->where('technologies.data.0.catalog.label', 'Chưa đồng bộ')
        ->where('technologies.data.1.name', 'PHP')
        ->where('technologies.data.1.catalog.label', 'Đã đồng bộ'));
});

it('queues a sync from the list', function () {
    actingAsAdmin();
    Queue::fake([SyncTechnologyCatalogJob::class]);
    $technology = Technology::factory()->create();

    post("/admin/technologies/{$technology->id}/sync")->assertInertiaFlash('toast.type', 'success');

    Queue::assertPushed(SyncTechnologyCatalogJob::class);
});

it('syncs again only when the product or the release source changed', function () {
    actingAsAdmin();
    fakeCatalogSources();
    Queue::fake([SyncTechnologyCatalogJob::class]);
    $technology = Technology::factory()->php()->create();

    put("/admin/technologies/{$technology->id}", ['endoflife_product' => 'php', 'name' => 'PHP (FPM)', 'slug' => 'php', 'release_source' => $technology->release_source->value])
        ->assertSessionHasNoErrors();
    Queue::assertNothingPushed();

    put("/admin/technologies/{$technology->id}", ['endoflife_product' => 'php', 'name' => 'PHP (FPM)', 'slug' => 'php', 'release_source' => 'endoflife']);
    Queue::assertPushed(SyncTechnologyCatalogJob::class);
});

it('keeps a technology that services use', function () {
    actingAsAdmin();
    $runtime = Runtime::factory()->create();

    get("/admin/technologies/{$runtime->technology_id}/edit")->assertInertia(fn (Assert $page) => $page->where('technology.is_used', true));
    delete("/admin/technologies/{$runtime->technology_id}")->assertInertiaFlash('toast.type', 'error');

    assertModelExists($runtime->technology);
});

it('deletes a technology nothing uses', function () {
    actingAsAdmin();
    $technology = Technology::factory()->create();

    delete("/admin/technologies/{$technology->id}")->assertRedirect('/admin/technologies');

    assertModelMissing($technology);
});

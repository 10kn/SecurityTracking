<?php

use App\Enums\Technology;
use App\Jobs\SyncTechnologyCatalogJob;
use App\Models\Runtime;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('lists every supported technology with the health of its catalog', function () {
    actingAsAdmin();
    syncedTechnology(Technology::Php);
    Runtime::factory()->create(['technology' => Technology::Php]);

    get('/admin/technologies')->assertInertia(fn (Assert $page) => $page
        ->component('technologies/index')
        ->has('technologies', 3)
        ->where('technologies.0.name', 'Node.js')
        ->where('technologies.0.catalog.label', 'Chưa đồng bộ')
        ->where('technologies.1.name', 'PHP')
        ->where('technologies.1.release_source', 'php.net')
        ->where('technologies.1.catalog.label', 'Đã đồng bộ')
        ->where('technologies.1.branches_count', 4)
        ->where('technologies.1.runtimes_count', 1));
});

it('shows the branches and the releases of a technology, newest first', function () {
    $this->travelTo('2026-10-23 10:00:00');
    actingAsAdmin();
    syncedTechnology(Technology::Php);

    get('/admin/technologies/php')->assertInertia(fn (Assert $page) => $page
        ->component('technologies/show')
        ->where('technology.name', 'PHP')
        ->where('technology.endoflife_url', 'https://endoflife.date/php')
        ->where('branches.0.branch', '8.5')
        ->where('branches.2.branch', '8.3')
        ->where('branches.2.support.label', 'Chỉ vá bảo mật')
        ->where('branches.3.support.label', 'Hết hỗ trợ')
        ->where('releases.data.0.version', '8.5.12')
        ->where('releases.meta.per_page', 10));
});

it('filters the releases by branch and version', function () {
    $this->travelTo('2026-10-23 10:00:00');
    actingAsAdmin();
    syncedTechnology(Technology::Php);

    get('/admin/technologies/php?branch=8.3&search=8.3.3')->assertInertia(fn (Assert $page) => $page
        ->where('table.branch', '8.3')
        ->where('releases.data.0.version', '8.3.36')
        ->where('releases.data.0.is_security_release', true)
        ->where('releases.data', fn ($releases) => $releases->every(fn (array $release): bool => str_starts_with($release['version'], '8.3.3'))));
});

it('answers 404 for a technology the system does not support', function () {
    actingAsAdmin();

    get('/admin/technologies/python')->assertNotFound();
});

it('queues a sync on demand', function () {
    actingAsAdmin();
    Queue::fake([SyncTechnologyCatalogJob::class]);

    post('/admin/technologies/ruby/sync')->assertInertiaFlash('toast.type', 'success');

    Queue::assertPushed(SyncTechnologyCatalogJob::class, fn (SyncTechnologyCatalogJob $job): bool => $job->technology === Technology::Ruby);
});

it('has no screens to add, edit or delete a technology', function () {
    actingAsAdmin();

    get('/admin/technologies/create')->assertNotFound();
    get('/admin/technologies/php/edit')->assertNotFound();
});

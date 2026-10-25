<?php

use App\Models\Project;
use App\Models\Service;
use App\Models\Team;

use function Pest\Laravel\getJson;

it('finds projects, services and teams by name or slug for the command palette', function () {
    actingAsAdmin();
    $project = Project::factory()->for(Team::factory()->create(['name' => 'Commerce', 'slug' => 'commerce']))->create(['name' => 'Shop', 'slug' => 'shop']);
    $service = Service::factory()->for($project)->create(['name' => 'shop-api', 'slug' => 'shop-api']);
    Service::factory()->create(['name' => 'rooms-api', 'slug' => 'rooms-api']);

    getJson('/admin/search?query=shop')
        ->assertOk()
        ->assertExactJson(['results' => [
            ['label' => 'Shop', 'hint' => 'Project · Commerce', 'url' => "/admin/projects/{$project->id}"],
            ['label' => 'shop-api', 'hint' => 'Service · Shop', 'url' => "/admin/services/{$service->id}/edit"],
        ]]);
});

it('finds the supported technologies by name', function () {
    actingAsAdmin();

    getJson('/admin/search?query=node')
        ->assertOk()
        ->assertExactJson(['results' => [
            ['label' => 'Node.js', 'hint' => 'Công nghệ', 'url' => '/admin/technologies/nodejs'],
        ]]);
});

it('returns nothing for an empty query', function () {
    actingAsAdmin();
    Team::factory()->create();

    getJson('/admin/search?query=%20')->assertExactJson(['results' => []]);
});

it('treats percent and underscore as plain text', function () {
    actingAsAdmin();
    Team::factory()->create(['name' => 'Payments', 'slug' => 'payments']);

    getJson('/admin/search?query=%25')->assertExactJson(['results' => []]);
    getJson('/admin/search?query=_')->assertExactJson(['results' => []]);
});

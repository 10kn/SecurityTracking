<?php

use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\assertModelMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

it('lists projects with their team and service count', function () {
    actingAsAdmin();
    $project = Project::factory()->for(Team::factory()->create(['name' => 'Commerce']))->create(['name' => 'Shop']);
    Service::factory()->count(2)->for($project)->create();

    get('/admin/projects')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->where('projects.data.0.name', 'Shop')
            ->where('projects.data.0.team.name', 'Commerce')
            ->where('projects.data.0.services_count', 2)
            ->where('teams.0.label', 'Commerce'));
});

it('filters projects by team and sorts them by team name', function () {
    actingAsAdmin();
    $commerce = Team::factory()->create(['name' => 'Commerce']);
    $booking = Team::factory()->create(['name' => 'Booking']);
    Project::factory()->for($commerce)->create(['name' => 'Shop']);
    Project::factory()->for($booking)->create(['name' => 'Rooms']);

    get("/admin/projects?team={$commerce->id}")->assertInertia(fn (Assert $page) => $page
        ->has('projects.data', 1)
        ->where('projects.data.0.name', 'Shop')
        ->where('table.team', $commerce->id));

    get('/admin/projects?sort=-team')->assertInertia(fn (Assert $page) => $page
        ->where('projects.data.0.name', 'Shop')
        ->where('projects.data.1.name', 'Rooms'));
});

it('creates a project and opens it', function () {
    actingAsAdmin();
    $team = Team::factory()->create();

    $response = post('/admin/projects', ['team_id' => $team->id, 'name' => 'Shop', 'slug' => 'shop']);

    $project = Project::firstWhere('slug', 'shop');
    $response->assertRedirect("/admin/projects/{$project->id}");
    expect($project->team_id)->toBe($team->id);
});

it('shows a project with its services and what they run', function () {
    actingAsAdmin();
    $project = Project::factory()->create();
    $service = Service::factory()->for($project)->create(['name' => 'shop-api']);
    $deployment = Deployment::factory()->for($service)->create();
    Runtime::factory()->for($deployment)->create(['raw_version' => '8.3.12']);
    Finding::factory()->for($deployment)->create(['team_id' => $project->team_id]);

    get("/admin/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('services.data.0.name', 'shop-api')
            ->where('services.data.0.open_findings_count', 1)
            ->has('services.data.0.runtimes', 1)
            ->where('services.data.0.lockfiles', []));
});

it('saves a project', function () {
    actingAsAdmin();
    $project = Project::factory()->create(['slug' => 'shop']);
    $team = Team::factory()->create();

    put("/admin/projects/{$project->id}", ['team_id' => $team->id, 'name' => 'Shop', 'slug' => 'shop'])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/admin/projects/{$project->id}");

    expect($project->refresh()->team_id)->toBe($team->id);
});

it('deletes a project with its services', function () {
    actingAsAdmin();
    $project = Project::factory()->create();
    $service = Service::factory()->for($project)->create();

    delete("/admin/projects/{$project->id}")->assertRedirect('/admin/projects');

    assertModelMissing($project);
    assertModelMissing($service);
});

<?php

use App\Jobs\SyncProjectLockfilesJob;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Support\Facades\Queue;
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

    $response = post('/admin/projects', ['team_id' => $team->id, 'name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@git.example.com:commerce/shop.git']);

    $project = Project::firstWhere('slug', 'shop');
    $response->assertRedirect("/admin/projects/{$project->id}");
    expect($project->team_id)->toBe($team->id);
});

it('rejects a repository url that is neither https nor a git remote', function (string $url) {
    actingAsAdmin();

    post('/admin/projects', ['team_id' => Team::factory()->create()->id, 'name' => 'Shop', 'slug' => 'shop', 'repository_url' => $url])
        ->assertSessionHasErrors(['repository_url' => 'Dùng URL https:// hoặc git@host:org/repo.']);
})->with([
    'http' => 'http://git.example.com/shop',
    'javascript' => 'javascript:alert(1)',
]);

it('shows a project with its services and what they run', function () {
    actingAsAdmin();
    $project = Project::factory()->create(['repository_url' => 'https://git.example.com/commerce/shop']);
    $service = Service::factory()->for($project)->create(['name' => 'shop-api']);
    $deployment = Deployment::factory()->for($service)->create();
    Runtime::factory()->for($deployment)->create(['raw_version' => '8.3.12']);
    Finding::factory()->for($deployment)->create(['team_id' => $project->team_id]);

    get("/admin/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.repository_href', 'https://git.example.com/commerce/shop')
            ->where('services.data.0.name', 'shop-api')
            ->where('services.data.0.open_findings_count', 1)
            ->has('services.data.0.runtimes', 1)
            ->where('services.data.0.lockfiles', []));
});

it('links only https repositories', function () {
    actingAsAdmin();
    $project = Project::factory()->create(['repository_url' => 'git@git.example.com:commerce/shop.git']);

    get("/admin/projects/{$project->id}")->assertInertia(fn (Assert $page) => $page->where('project.repository_href', null));
});

it('saves a project', function () {
    actingAsAdmin();
    $project = Project::factory()->create(['slug' => 'shop']);
    $team = Team::factory()->create();

    put("/admin/projects/{$project->id}", ['team_id' => $team->id, 'name' => 'Shop', 'slug' => 'shop', 'repository_url' => null])
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

it('reads the lockfiles right away when a project starts syncing them', function () {
    actingAsAdmin();
    Queue::fake([SyncProjectLockfilesJob::class]);
    $project = Project::factory()->create(['slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop']);

    put("/admin/projects/{$project->id}", ['team_id' => $project->team_id, 'name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop', 'repository_branch' => 'release/2026-10', 'syncs_lockfiles' => true])
        ->assertSessionHasNoErrors();

    expect($project->refresh())->syncs_lockfiles->toBeTrue()->repository_branch->toBe('release/2026-10');
    Queue::assertPushed(SyncProjectLockfilesJob::class, fn (SyncProjectLockfilesJob $job): bool => $job->project->is($project));
});

it('does not read the lockfiles again when the repository settings stay the same', function () {
    actingAsAdmin();
    Queue::fake([SyncProjectLockfilesJob::class]);
    $project = Project::factory()->syncingLockfiles('main')->create(['slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop']);

    put("/admin/projects/{$project->id}", ['team_id' => $project->team_id, 'name' => 'Shop (renamed)', 'slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop', 'repository_branch' => 'main', 'syncs_lockfiles' => true])
        ->assertSessionHasNoErrors();

    Queue::assertNothingPushed();
});

it('syncs lockfiles only from a github repository', function (?string $url) {
    actingAsAdmin();

    post('/admin/projects', ['team_id' => Team::factory()->create()->id, 'name' => 'Shop', 'slug' => 'shop', 'repository_url' => $url, 'syncs_lockfiles' => true])
        ->assertSessionHasErrors(['syncs_lockfiles' => 'Cần repository URL trên GitHub, ví dụ https://github.com/org/repo.']);
})->with([
    'no repository' => null,
    'another host' => 'https://git.example.com/acme/shop',
]);

it('rejects a branch name git would not accept', function (string $branch) {
    actingAsAdmin();

    post('/admin/projects', ['team_id' => Team::factory()->create()->id, 'name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop', 'repository_branch' => $branch])
        ->assertSessionHasErrors(['repository_branch' => 'Tên branch không hợp lệ.']);
})->with(['main..dev', '-main', 'feature branch']);

it('shows how the lockfiles of a project are synced', function () {
    actingAsAdmin();
    configureGitHubApp();
    $project = Project::factory()->syncingLockfiles()->create([
        'repository_url' => 'https://github.com/acme/shop',
        'lockfiles_synced_at' => '2026-10-24 06:30:00',
        'lockfiles_commit_sha' => GITHUB_COMMIT,
    ]);

    get("/admin/projects/{$project->id}")->assertInertia(fn (Assert $page) => $page
        ->where('project.lockfile_sync.status.label', 'Đã đồng bộ')
        ->where('project.lockfile_sync.commit', '3f78685')
        ->where('project.lockfile_sync.commit_href', 'https://github.com/acme/shop/commit/'.GITHUB_COMMIT)
        ->where('project.lockfile_sync.error', null));

    get("/admin/projects/{$project->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('project.syncs_lockfiles', true)
        ->where('gitHubConfigured', true));
});

it('queues reading the lockfiles of a project on request', function () {
    actingAsAdmin();
    Queue::fake([SyncProjectLockfilesJob::class]);
    $project = Project::factory()->syncingLockfiles()->create();

    post("/admin/projects/{$project->id}/lockfile-sync")->assertRedirect();

    Queue::assertPushed(SyncProjectLockfilesJob::class);
});

it('has nothing to sync for a project that does not read its lockfiles from github', function () {
    actingAsAdmin();
    Queue::fake([SyncProjectLockfilesJob::class]);

    post('/admin/projects/'.Project::factory()->create()->id.'/lockfile-sync')->assertNotFound();

    Queue::assertNothingPushed();
});

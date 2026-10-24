<?php

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\ServicesRelationManager;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\Team;
use App\Models\Technology;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

it('creates a project for a team', function () {
    actingAsAdmin();
    $team = Team::factory()->create();

    Livewire::test(CreateProject::class)
        ->fillForm([
            'team_id' => $team->id,
            'name' => 'Shop Platform',
            'repository_url' => 'git@git.example.com:commerce/shop-platform.git',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas(Project::class, ['team_id' => $team->id, 'slug' => 'shop-platform']);
});

it('rejects a repository url that is neither https nor an scp-style git remote', function (string $repositoryUrl) {
    actingAsAdmin();

    Livewire::test(CreateProject::class)
        ->fillForm(['team_id' => Team::factory()->create()->id, 'name' => 'Shop', 'repository_url' => $repositoryUrl])
        ->call('create')
        ->assertHasFormErrors(['repository_url' => 'regex']);
})->with([
    'javascript' => 'javascript:alert(1)',
    'plain http' => 'http://git.example.com/shop',
]);

it('lists the services of a project with their runtime versions', function () {
    actingAsAdmin();
    $project = Project::factory()->create();
    $service = Service::factory()->for($project)->create();
    Runtime::factory()->for(Deployment::factory()->for($service))->for(Technology::factory()->php())->create(['raw_version' => '8.3.12']);

    Livewire::test(ServicesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->assertCanSeeTableRecords([$service])
        ->assertSeeText('PHP 8.3.12')
        ->assertSeeText('Thiếu dữ liệu');
});

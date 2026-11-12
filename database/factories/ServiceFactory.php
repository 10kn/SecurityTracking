<?php

namespace Database\Factories;

use App\Enums\Exposure;
use App\Enums\ServiceKind;
use App\Models\Project;
use App\Models\RepositoryConnection;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'project_id' => Project::factory(),
            'name' => $slug,
            'slug' => $slug,
            'kind' => ServiceKind::Server,
            'exposure' => Exposure::Internal,
            'repository_path' => '/',
        ];
    }

    /**
     * A service that reads its lockfiles from a GitHub repository every day, through the GitHub App or $connection.
     */
    public function syncingLockfiles(string $repository = 'acme/shop', ?string $branch = null, ?RepositoryConnection $connection = null): static
    {
        return $this->state([
            'repository' => $repository,
            'repository_connection_id' => $connection?->id,
            'repository_branch' => $branch,
        ]);
    }
}

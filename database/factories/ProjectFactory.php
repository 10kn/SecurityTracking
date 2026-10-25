<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'team_id' => Team::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'repository_url' => 'https://github.com/example/'.Str::slug($name),
        ];
    }

    /**
     * A project that reads its lockfiles from its GitHub repository every day.
     */
    public function syncingLockfiles(?string $branch = null): static
    {
        return $this->state(fn (array $attributes) => [
            'repository_url' => 'https://github.com/acme/'.Str::slug((string) $attributes['name']),
            'repository_branch' => $branch,
            'syncs_lockfiles' => true,
        ]);
    }
}

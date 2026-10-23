<?php

namespace Database\Factories;

use App\Enums\Exposure;
use App\Enums\ServiceKind;
use App\Models\Project;
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
}

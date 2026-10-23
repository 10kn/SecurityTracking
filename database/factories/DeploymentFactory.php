<?php

namespace Database\Factories;

use App\Enums\Environment;
use App\Models\Deployment;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'environment' => Environment::Production,
            'commit_sha' => fake()->sha1(),
            'is_current' => true,
            'deployed_at' => now()->subDays(fake()->numberBetween(1, 30)),
        ];
    }
}

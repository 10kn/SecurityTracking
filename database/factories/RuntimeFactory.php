<?php

namespace Database\Factories;

use App\Enums\RuntimeSource;
use App\Enums\RuntimeStatus;
use App\Enums\Technology;
use App\Models\Deployment;
use App\Models\Runtime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Runtime>
 */
class RuntimeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deployment_id' => Deployment::factory(),
            'technology' => Technology::Php,
            'raw_version' => '8.4.1',
            'version' => '8.4.1',
            'source' => RuntimeSource::Manual,
            'status' => RuntimeStatus::Unknown,
            'observed_at' => now(),
        ];
    }
}

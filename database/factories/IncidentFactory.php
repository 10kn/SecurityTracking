<?php

namespace Database\Factories;

use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'technology' => Technology::Php,
            'title' => fake()->sentence(6),
            'severity' => Severity::Critical,
            'first_published_at' => now()->subDay(),
            'changed_at' => now()->subDay(),
        ];
    }
}

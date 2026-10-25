<?php

namespace Database\Factories;

use App\Models\AccessibleRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessibleRepository>
 */
class AccessibleRepositoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => 'acme/'.fake()->unique()->slug(2),
            'default_branch' => 'main',
            'is_private' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\GitHubRunnerImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitHubRunnerImage>
 */
class GitHubRunnerImageFactory extends Factory
{
    /**
     * Define the model's default state: an image GitHub has not announced a removal for.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'ubuntu-'.fake()->unique()->numberBetween(20, 99).'.04',
            'eol_from' => null,
            'is_eol' => false,
            'synced_at' => now(),
        ];
    }

    /**
     * An image GitHub already removed.
     */
    public function removed(): static
    {
        return $this->state(fn (array $attributes) => [
            'eol_from' => now()->subMonth()->toDateString(),
            'is_eol' => true,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\RuntimeBranch;
use App\Models\Technology;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuntimeBranch>
 */
class RuntimeBranchFactory extends Factory
{
    /**
     * Define the model's default state: a branch in active support.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'technology_id' => Technology::factory(),
            'branch' => '8.'.fake()->unique()->numberBetween(0, 99),
            'latest_version' => fn (array $attributes) => $attributes['branch'].'.10',
            'released_at' => now()->subYear(),
            'active_support_until' => now()->addYear(),
            'security_support_until' => now()->addYears(2),
            'is_eol' => false,
            'synced_at' => now(),
        ];
    }

    /**
     * Active support has ended; the branch only receives security fixes.
     */
    public function securityOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'active_support_until' => now()->subMonth(),
            'security_support_until' => now()->addYear(),
        ]);
    }

    /**
     * The branch no longer receives security fixes.
     */
    public function eol(): static
    {
        return $this->state(fn (array $attributes) => [
            'active_support_until' => now()->subYears(2),
            'security_support_until' => now()->subYear(),
            'is_eol' => true,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\Language;
use App\Models\RuntimeBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuntimeBranch>
 */
class RuntimeBranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'language' => Language::Php,
            'branch' => '8.'.fake()->unique()->numberBetween(0, 99),
            'latest_version' => fn (array $attributes) => $attributes['branch'].'.10',
            'active_support_until' => now()->addYear(),
            'security_support_until' => now()->addYears(2),
            'synced_at' => now(),
        ];
    }

    /**
     * The branch no longer receives security fixes.
     */
    public function eol(): static
    {
        return $this->state(fn (array $attributes) => [
            'active_support_until' => now()->subYears(2),
            'security_support_until' => now()->subYear(),
        ]);
    }
}

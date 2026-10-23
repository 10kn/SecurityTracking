<?php

namespace Database\Factories;

use App\Enums\Language;
use App\Models\RuntimeRelease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuntimeRelease>
 */
class RuntimeReleaseFactory extends Factory
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
            'branch' => '8.3',
            'version' => fn (array $attributes) => $attributes['branch'].'.'.fake()->unique()->numberBetween(0, 99),
            'is_security_release' => false,
            'released_at' => now()->subWeek(),
        ];
    }

    public function security(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_security_release' => true,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\SourceHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceHeartbeat>
 */
class SourceHeartbeatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $source = fake()->unique()->slug(2);

        return [
            'source' => $source,
            'name' => $source,
            'threshold_minutes' => 180,
            'last_success_at' => now()->subMinutes(10),
        ];
    }

    /**
     * The source has not synced successfully within its threshold.
     */
    public function stale(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_success_at' => now()->subMinutes($attributes['threshold_minutes'] + 1),
            'last_failure_at' => now()->subMinutes(5),
            'last_error' => 'cURL error 28: Operation timed out',
        ]);
    }
}

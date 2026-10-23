<?php

namespace Database\Factories;

use App\Enums\ChatNotificationKind;
use App\Models\ChatNotification;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatNotification>
 */
class ChatNotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'dedupe_key' => 'immediate:'.fake()->unique()->sha1(),
            'kind' => ChatNotificationKind::Immediate,
            'payload' => ['text' => fake()->sentence()],
            'attempts' => 0,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'attempts' => 1,
            'sent_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'attempts' => 5,
            'failed_at' => now(),
            'last_error' => '404 Not Found',
        ]);
    }

    /**
     * A message for the admin space rather than a team space.
     */
    public function forAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => null,
            'kind' => ChatNotificationKind::AdminAlert,
        ]);
    }
}

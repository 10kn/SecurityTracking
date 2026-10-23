<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Team '.fake()->unique()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }

    public function withGoogleChatWebhook(): static
    {
        return $this->state(fn (array $attributes) => [
            'google_chat_webhook_url' => 'https://chat.googleapis.com/v1/spaces/DEMO/messages?key=demo-key&token=demo-token',
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\NotificationTarget;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'team_id' => Team::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
        ];
    }

    /**
     * A project whose messages go to a Google Chat webhook of its own instead of its team's.
     */
    public function withOwnWebhook(?string $webhookUrl = null): static
    {
        return $this->state(fn (array $attributes) => [
            'notification_target' => NotificationTarget::Project,
            'google_chat_webhook_url' => $webhookUrl ?? 'https://chat.googleapis.com/v1/spaces/'.Str::upper(Str::random(11)).'/messages?key=test&token=test',
        ]);
    }

    /**
     * A project whose monitoring an admin paused.
     */
    public function paused(string $reason = 'Đã ngừng maintain.'): static
    {
        return $this->state(fn (array $attributes) => [
            'monitoring_paused_at' => now(),
            'monitoring_pause_reason' => $reason,
        ]);
    }
}

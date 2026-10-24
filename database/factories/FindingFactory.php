<?php

namespace Database\Factories;

use App\Enums\FindingRule;
use App\Enums\FindingState;
use App\Enums\Severity;
use App\Models\Deployment;
use App\Models\Finding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Finding>
 */
class FindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The team defaults to the team that owns the deployment's project.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'deployment_id' => Deployment::factory(),
            'team_id' => fn (array $attributes) => Deployment::findOrFail($attributes['deployment_id'])->service->project->team_id,
            'rule' => FindingRule::RuntimeEol,
            'severity' => Severity::High,
            'state' => FindingState::Open,
            'title' => 'PHP 8.1 hết hỗ trợ',
            'group_key' => 'runtime_eol:demo:'.fake()->unique()->numerify('####'),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    /**
     * The team was already told about the finding at its current severity.
     */
    public function notified(): static
    {
        return $this->state(fn (array $attributes) => [
            'notified_severity' => $attributes['severity'],
            'notified_at' => now(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => FindingState::Resolved,
            'resolved_at' => now(),
        ]);
    }
}

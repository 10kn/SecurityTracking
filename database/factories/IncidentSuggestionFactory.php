<?php

namespace Database\Factories;

use App\Enums\IncidentSuggestionReason;
use App\Enums\IncidentSuggestionStatus;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentSuggestion>
 */
class IncidentSuggestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'advisory_id' => Advisory::factory()->preNotice(Technology::Php),
            'incident_id' => Incident::factory(),
            'reason' => IncidentSuggestionReason::ReleaseWindow,
            'status' => IncidentSuggestionStatus::Pending,
        ];
    }
}

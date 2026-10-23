<?php

namespace Database\Factories;

use App\Enums\AdvisoryKind;
use App\Enums\Language;
use App\Enums\Severity;
use App\Models\Advisory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Advisory>
 */
class AdvisoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $externalId = 'DEMO-GHSA-'.fake()->unique()->numerify('####');

        return [
            'source' => 'osv',
            'external_id' => $externalId,
            'kind' => AdvisoryKind::Vulnerability,
            'aliases' => ['DEMO-CVE-'.fake()->numerify('####')],
            'title' => fake()->sentence(6),
            'summary' => fake()->paragraph(),
            'severity' => Severity::High,
            'cvss_score' => 7.5,
            'url' => 'https://osv.dev/vulnerability/'.$externalId,
            'published_at' => now()->subDay(),
        ];
    }

    /**
     * A vendor pre-notice that announces an upcoming security release.
     */
    public function preNotice(Language $language = Language::Php): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => $language->value.'_feed',
            'kind' => AdvisoryKind::PreNotice,
            'aliases' => [],
            'severity' => Severity::Critical,
            'cvss_score' => null,
            'language' => $language,
        ]);
    }
}

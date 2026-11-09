<?php

namespace Database\Factories;

use App\Enums\AdvisoryKind;
use App\Enums\LinuxDistribution;
use App\Enums\Severity;
use App\Enums\Technology;
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
    public function preNotice(Technology $technology): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => $technology->noticeSourceKey(),
            'kind' => AdvisoryKind::PreNotice,
            'aliases' => [],
            'severity' => Severity::Critical,
            'cvss_score' => null,
            'technology' => $technology,
        ]);
    }

    /**
     * A security advisory of an operating system, e.g. an Ubuntu notice fixing 24.04.
     */
    public function osAdvisory(LinuxDistribution $os = LinuxDistribution::Ubuntu): static
    {
        return $this->state(function (array $attributes) use ($os) {
            $externalId = 'DEMO-USN-'.fake()->unique()->numerify('####').'-1';

            return [
                'source' => $os->sourceKey(),
                'external_id' => $externalId,
                'kind' => AdvisoryKind::OsAdvisory,
                'operating_system' => $os,
                'title' => "{$externalId}: ".fake()->words(2, true).' vulnerabilities',
                'cvss_score' => null,
                'url' => null,
                'os_releases' => ['24.04'],
                'fixed_packages' => [['release' => '24.04', 'name' => 'openssl', 'version' => '3.0.13-0ubuntu3.6']],
            ];
        });
    }

    /**
     * A pre-notice whose text says its vulnerability is exploited.
     */
    public function exploited(string $excerpt = 'The vulnerability is being actively exploited in the wild.'): static
    {
        return $this->state(fn (array $attributes) => [
            'is_exploited' => true,
            'exploited_basis' => ['method' => 'keyword', 'excerpt' => $excerpt, 'content_hash' => hash('sha256', $excerpt)],
        ]);
    }
}

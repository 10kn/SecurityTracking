<?php

namespace Database\Factories;

use App\Models\SecurityNewsArticle;
use App\Services\Notices\JpcertNewsFeed;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SecurityNewsArticle> */
class SecurityNewsArticleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'url' => JpcertNewsFeed::ORIGIN.'/newsflash/'.fake()->unique()->numerify('##########').'.html',
            'section' => JpcertNewsFeed::FLASH,
            'title' => fake()->sentence(),
            'listed_on' => today()->toDateString(),
            'listed_seen_at' => now(),
            'published_on' => today()->toDateString(),
            'is_backfill' => false,
            'accepted_at' => now(),
            'summary' => fake()->sentence(),
            'reason' => fake()->sentence(),
            'matched_tags' => ['OpenSSH'],
            'cve_ids' => [],
        ];
    }
}

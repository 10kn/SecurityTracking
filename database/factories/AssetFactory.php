<?php

namespace Database\Factories;

use App\Enums\AssetKind;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hostname = fake()->unique()->domainWord().'-01';

        return [
            'identifier' => $hostname,
            'kind' => AssetKind::Host,
            'hostname' => $hostname,
            'last_seen_at' => now()->subMinutes(fake()->numberBetween(1, 50)),
        ];
    }
}

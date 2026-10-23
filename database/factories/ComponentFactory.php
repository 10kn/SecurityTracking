<?php

namespace Database\Factories;

use App\Enums\Ecosystem;
use App\Models\Component;
use App\Models\Deployment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Component>
 */
class ComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'demo/'.fake()->unique()->slug(2);
        $version = fake()->semver();

        return [
            'deployment_id' => Deployment::factory(),
            'ecosystem' => Ecosystem::Packagist,
            'name' => $name,
            'version' => $version,
            'purl' => "pkg:composer/{$name}@{$version}",
            'lockfile_path' => 'composer.lock',
            'is_dev' => false,
        ];
    }

    public function dev(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_dev' => true,
        ]);
    }
}

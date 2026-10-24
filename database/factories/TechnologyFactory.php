<?php

namespace Database\Factories;

use App\Enums\ReleaseSource;
use App\Models\Technology;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Technology>
 */
class TechnologyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = fake()->unique()->slug(2);

        return [
            'name' => Str::headline($product),
            'slug' => $product,
            'endoflife_product' => $product,
            'release_source' => ReleaseSource::Endoflife,
        ];
    }

    public function php(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'PHP',
            'slug' => 'php',
            'endoflife_product' => 'php',
            'release_source' => ReleaseSource::PhpNet,
        ]);
    }

    public function nodejs(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Node.js',
            'slug' => 'nodejs',
            'endoflife_product' => 'nodejs',
            'release_source' => ReleaseSource::Nodejs,
        ]);
    }

    public function ruby(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Ruby',
            'slug' => 'ruby',
            'endoflife_product' => 'ruby',
            'release_source' => ReleaseSource::RubyLang,
        ]);
    }
}

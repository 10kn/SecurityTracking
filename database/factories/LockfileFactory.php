<?php

namespace Database\Factories;

use App\Enums\Ecosystem;
use App\Models\Deployment;
use App\Models\Lockfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lockfile>
 */
class LockfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = json_encode(['packages' => [], 'packages-dev' => []], JSON_PRETTY_PRINT);

        return [
            'deployment_id' => Deployment::factory(),
            'ecosystem' => Ecosystem::Packagist,
            'path' => 'composer.lock',
            'content' => $content,
            'content_hash' => hash('sha256', (string) $content),
            'uploaded_at' => now(),
        ];
    }
}

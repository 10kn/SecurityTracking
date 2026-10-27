<?php

namespace Database\Factories;

use App\Enums\Ecosystem;
use App\Enums\LockfileSource;
use App\Models\Deployment;
use App\Models\Lockfile;
use App\Support\LockfilePath;
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

    /**
     * A lockfile with the given name and content, e.g. a fixture from tests/Fixtures/lockfiles.
     */
    public function withContent(string $path, string $content): static
    {
        return $this->state(fn (array $attributes) => [
            'ecosystem' => LockfilePath::ecosystem($path) ?? Ecosystem::Packagist,
            'path' => $path,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
        ]);
    }

    /**
     * A lockfile read from the project's GitHub repository at the commit.
     */
    public function fromGitHub(string $commitSha): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => LockfileSource::GitHub,
            'commit_sha' => $commitSha,
        ]);
    }
}

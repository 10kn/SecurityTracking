<?php

namespace Database\Factories;

use App\Models\RepositoryConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RepositoryConnection>
 */
class RepositoryConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'token' => 'ghp_'.Str::random(36),
            'account_login' => 'dev-'.fake()->unique()->userName(),
            'scopes' => 'repo',
            'expires_at' => now()->addDays(90),
            'last_checked_at' => now(),
        ];
    }

    /**
     * A token GitHub turned down when it was last checked.
     */
    public function rejected(string $error = 'Token bị thu hồi hoặc đã hết hạn.'): static
    {
        return $this->state(['last_error' => $error]);
    }
}

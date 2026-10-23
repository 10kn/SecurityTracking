<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with an admin account (password "password") and demo data.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder creates an admin with a known password and must not run in production.');
        }

        User::factory()->admin()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);

        $this->call(DemoSeeder::class);
    }
}

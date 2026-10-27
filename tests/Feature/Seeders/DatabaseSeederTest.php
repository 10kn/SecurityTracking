<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\post;
use function Pest\Laravel\seed;

it('seeds an admin account', function () {
    seed(DatabaseSeeder::class);

    expect(User::where('email', 'admin@example.com')->sole()->is_admin)->toBeTrue();
});

it('lets the seeded admin sign in to the admin screens', function () {
    seed(DatabaseSeeder::class);

    post('/admin/login', ['email' => 'admin@example.com', 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin');

    assertAuthenticatedAs(User::where('email', 'admin@example.com')->sole());
});

it('refuses to seed in production', function () {
    app()['env'] = 'production';

    expect(fn () => app(DatabaseSeeder::class)->run())->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0);
});

<?php

use App\Enums\Environment;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\seed;

it('seeds an admin account and the demo teams and services', function () {
    seed(DatabaseSeeder::class);

    $admin = User::where('email', 'admin@example.com')->sole();
    $services = Service::with('deployments.runtimes')->get();

    expect($admin->is_admin)->toBeTrue()
        ->and(Team::pluck('name')->sort()->values()->all())->toBe(['Booking', 'Commerce', 'Internal Tools'])
        ->and($services)->toHaveCount(8)
        ->and($services->every(fn (Service $service): bool => $service->deployments->sole()->environment === Environment::Production
            && $service->deployments->sole()->runtimes->count() === 1))->toBeTrue();
});

it('lets the seeded admin sign in to the admin panel', function () {
    seed(DatabaseSeeder::class);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'admin@example.com', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect('/admin');

    assertAuthenticatedAs(User::where('email', 'admin@example.com')->sole());
});

it('refuses to seed in production', function () {
    app()['env'] = 'production';

    expect(fn () => app(DatabaseSeeder::class)->run())->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0);
});

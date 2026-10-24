<?php

use App\Enums\Locale;
use App\Enums\Theme;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('renders the admin screens in the language the user chose', function () {
    actingAs(User::factory()->admin()->create(['locale' => Locale::Japanese]));

    get('/admin/teams')
        ->assertOk()
        ->assertSee('lang="ja"', escape: false)
        ->assertSee('japanese', escape: false);
});

it('renders vietnamese by default, without the japanese font', function () {
    actingAsAdmin();

    get('/admin/teams')
        ->assertSee('lang="vi"', escape: false)
        ->assertDontSee('japanese', escape: false);
});

it('renders the theme the user chose', function (Theme $theme) {
    actingAs(User::factory()->admin()->create(['theme' => $theme]));

    get('/admin/teams')->assertSee('data-theme="'.$theme->value.'"', escape: false);
})->with(Theme::cases());

it('keeps the chosen language and theme after signing in again', function () {
    User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'theme' => Theme::Forest,
        'locale' => Locale::Japanese,
    ]);

    post('/admin/login', ['email' => 'admin@example.com', 'password' => 'password']);

    get('/admin/teams')
        ->assertSee('lang="ja"', escape: false)
        ->assertSee('data-theme="forest"', escape: false);
});

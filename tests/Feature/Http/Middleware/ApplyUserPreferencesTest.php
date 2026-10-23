<?php

use App\Enums\Locale;
use App\Enums\Theme;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('renders the panel in the language the user chose', function () {
    actingAs(User::factory()->admin()->create(['locale' => Locale::Japanese]));

    get('/admin/teams')
        ->assertOk()
        ->assertSee('lang="ja"', escape: false)
        ->assertSeeText('チーム');
});

it('renders vietnamese by default', function () {
    actingAsAdmin();

    get('/admin/teams')
        ->assertSee('lang="vi"', escape: false)
        ->assertSeeText('Cài đặt');
});

it('forces dark mode only for the night theme', function (Theme $theme, bool $isDark) {
    actingAs(User::factory()->admin()->create(['theme' => $theme]));

    $response = get('/admin/teams')
        ->assertSee('document.documentElement.dataset.theme = "'.$theme->value.'"', escape: false);

    $isDark
        ? $response->assertSee("localStorage.setItem('filament:theme:mode', 'dark')", escape: false)
        : $response->assertSee("localStorage.setItem('filament:theme:mode', 'light')", escape: false);
})->with([
    'graphite' => [Theme::Graphite, false],
    'ocean' => [Theme::Ocean, false],
    'forest' => [Theme::Forest, false],
    'night' => [Theme::Night, true],
]);

it('uses the theme palette for the primary color', function () {
    actingAs(User::factory()->admin()->create(['theme' => Theme::Ocean]));

    get('/admin/teams')
        ->assertSee('--primary-600:'.Theme::Ocean->colors()['primary'][600], escape: false);
});

it('keeps the chosen language and theme after signing in again', function () {
    User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'theme' => Theme::Forest,
        'locale' => Locale::Japanese,
    ]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'admin@example.com', 'password' => 'password'])
        ->call('authenticate');

    get('/admin/teams')
        ->assertSee('lang="ja"', escape: false)
        ->assertSee('document.documentElement.dataset.theme = "forest"', escape: false);
});

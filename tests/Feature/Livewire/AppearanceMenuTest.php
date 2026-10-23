<?php

use App\Enums\Locale;
use App\Enums\Theme;
use App\Livewire\AppearanceMenu;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

it('saves the chosen theme and reloads the current page', function () {
    $admin = actingAsAdmin();

    $component = Livewire::test(AppearanceMenu::class);

    $component->call('setTheme', 'night')
        ->assertRedirect($component->get('returnUrl'));

    expect($admin->refresh()->theme)->toBe(Theme::Night);
});

it('saves the chosen interface language', function () {
    $admin = actingAsAdmin();

    Livewire::test(AppearanceMenu::class)
        ->call('setLocale', 'ja');

    expect($admin->refresh()->locale)->toBe(Locale::Japanese);
});

it('rejects a theme or language that does not exist', function (string $method, string $value) {
    $admin = actingAsAdmin();

    Livewire::test(AppearanceMenu::class)
        ->call($method, $value)
        ->assertStatus(422);

    expect($admin->refresh())
        ->theme->toBe(Theme::Graphite)
        ->locale->toBe(Locale::Vietnamese);
})->with([
    'theme' => ['setTheme', 'neon'],
    'language' => ['setLocale', 'fr'],
]);

it('does not let the client change where it redirects', function () {
    actingAsAdmin();

    Livewire::test(AppearanceMenu::class)
        ->set('returnUrl', 'https://attacker.example');
})->throws(CannotUpdateLockedPropertyException::class);

<?php

use App\Enums\Locale;
use App\Enums\Theme;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\put;

it('saves the theme and the language on the user', function () {
    $admin = actingAsAdmin();

    put('/admin/preferences', ['theme' => 'night'])->assertRedirect();
    put('/admin/preferences', ['locale' => 'ja'])->assertRedirect();

    expect($admin->refresh())
        ->theme->toBe(Theme::Night)
        ->locale->toBe(Locale::Japanese);
});

it('rejects an unknown theme or language', function () {
    actingAsAdmin();

    put('/admin/preferences', ['theme' => 'neon', 'locale' => 'fr'])->assertSessionHasErrors(['theme', 'locale']);
});

it('renders the page in the theme and language of the user', function () {
    actingAs(User::factory()->admin()->create(['theme' => Theme::Ocean, 'locale' => Locale::Japanese]));

    get('/admin/teams')
        ->assertSee('lang="ja" data-theme="ocean"', escape: false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('preferences', ['theme' => 'ocean', 'locale' => 'ja'])
            ->where('translations.Teams', 'チーム')
            ->has('themes', 4));
});

it('sends no translations for vietnamese, the language strings are written in', function () {
    actingAsAdmin();

    get('/admin/teams')->assertInertia(fn (Assert $page) => $page->where('translations', null));
});

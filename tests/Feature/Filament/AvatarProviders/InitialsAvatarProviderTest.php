<?php

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Models\User;

it('draws the initials locally instead of calling an external avatar service', function () {
    $avatar = app(InitialsAvatarProvider::class)->get(User::factory()->make(['name' => 'Giang Nguyễn']));

    expect($avatar)->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,'))))->toContain('>GN</text>');
});

it('keeps markup in the name out of the svg', function () {
    $avatar = app(InitialsAvatarProvider::class)->get(User::factory()->make(['name' => '<script>alert(1)</script> Admin']));

    expect(base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,'))))->not->toContain('<script')->toContain('>SA</text>');
});

it('does not render ui-avatars.com in the panel', function () {
    actingAsAdmin();

    $this->get('/admin/teams')->assertDontSee('ui-avatars.com');
});

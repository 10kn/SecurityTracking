<?php

use App\Enums\Locale;
use App\Enums\Theme;
use App\Models\Team;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('lets admins open the admin panel', function () {
    actingAs(User::factory()->admin()->create())
        ->get('/admin/teams')
        ->assertOk();
});

it('forbids team members who are not admins from the admin panel', function () {
    $member = User::factory()->hasAttached(Team::factory())->create();

    actingAs($member)
        ->get('/admin/teams')
        ->assertForbidden();
});

it('defaults new users to the graphite theme and the vietnamese locale', function () {
    $user = User::factory()->create()->refresh();

    expect($user->theme)->toBe(Theme::Graphite)
        ->and($user->locale)->toBe(Locale::Vietnamese);
});

it('takes the avatar initials from the first two words of the name', function (string $name, string $initials) {
    expect(User::factory()->make(['name' => $name])->initials())->toBe($initials);
})->with([
    'vietnamese' => ['Giang Nguyễn', 'GN'],
    'one word' => ['admin', 'A'],
    'markup' => ['<script>alert(1)</script> Admin', 'SA'],
]);

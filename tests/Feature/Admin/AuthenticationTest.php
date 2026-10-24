<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('sends guests to the login page', function () {
    get('/admin/projects')->assertRedirect('/admin/login');

    get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/login'));
});

it('signs an admin in and back out', function () {
    $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);

    post('/admin/login', ['email' => 'admin@example.com', 'password' => 'password'])->assertRedirect('/admin');
    assertAuthenticatedAs($admin);

    post('/admin/logout')->assertRedirect('/admin/login');
    assertGuest();
});

it('rejects a wrong password', function () {
    User::factory()->admin()->create(['email' => 'admin@example.com']);

    post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong'])->assertSessionHasErrors(['email' => 'Email hoặc mật khẩu không đúng.']);
    assertGuest();
});

it('does not let anyone but admins sign in', function () {
    User::factory()->create(['email' => 'dev@example.com']);

    post('/admin/login', ['email' => 'dev@example.com', 'password' => 'password'])->assertSessionHasErrors(['email' => 'Email hoặc mật khẩu không đúng.']);
    assertGuest();
});

it('forbids the admin screens to a signed-in user who is not an admin', function () {
    actingAs(User::factory()->create());

    get('/admin/projects')->assertForbidden();
});

it('limits login attempts', function () {
    foreach (range(1, 6) as $attempt) {
        post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong']);
    }

    post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong'])->assertTooManyRequests();
});

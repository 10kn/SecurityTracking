<?php

use App\Models\ChatNotification;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertModelExists;
use function Pest\Laravel\assertModelMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

it('lists teams with their projects, open findings and google chat status', function () {
    actingAsAdmin();
    $failing = Team::factory()->withGoogleChatWebhook()->create(['name' => 'Booking']);
    ChatNotification::factory()->for($failing)->failed()->create();
    Finding::factory()->count(2)->for(Deployment::factory()->for(Service::factory()->for(Project::factory()->for($failing))))->create(['team_id' => $failing->id]);
    Finding::factory()->resolved()->for(Deployment::factory()->for(Service::factory()->for(Project::factory()->for($failing))))->create(['team_id' => $failing->id]);
    Team::factory()->create(['name' => 'Internal Tools']);

    get('/admin/teams')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/index')
            ->has('teams.data', 2)
            ->where('teams.data.0.name', 'Booking')
            ->where('teams.data.0.projects_count', 2)
            ->where('teams.data.0.open_findings_count', 2)
            ->where('teams.data.0.google_chat', ['label' => 'Lỗi gửi', 'tone' => 'danger'])
            ->where('teams.data.1.google_chat', ['label' => 'Chưa cấu hình', 'tone' => 'neutral'])
            ->where('teams.meta.total', 2));
});

it('searches teams by name or slug and sorts them', function () {
    actingAsAdmin();
    Team::factory()->create(['name' => 'Payments', 'slug' => 'payments']);
    Team::factory()->create(['name' => 'Booking', 'slug' => 'travel']);
    Team::factory()->create(['name' => 'Commerce', 'slug' => 'commerce']);

    get('/admin/teams?search=travel')->assertInertia(fn (Assert $page) => $page
        ->has('teams.data', 1)
        ->where('teams.data.0.name', 'Booking')
        ->where('table.search', 'travel'));

    get('/admin/teams?sort=-name')->assertInertia(fn (Assert $page) => $page
        ->where('teams.data.0.name', 'Payments')
        ->where('table.sort', '-name'));
});

it('falls back to the default order for an unknown sort column', function () {
    actingAsAdmin();
    Team::factory()->create(['name' => 'Payments']);
    Team::factory()->create(['name' => 'Booking']);

    get('/admin/teams?sort=google_chat_webhook_url')->assertInertia(fn (Assert $page) => $page
        ->where('teams.data.0.name', 'Booking')
        ->where('table.sort', 'name'));
});

it('creates a team and opens it to add the webhook', function () {
    actingAsAdmin();

    $response = post('/admin/teams', ['name' => 'Internal Tools', 'slug' => 'internal-tools']);

    $team = Team::firstWhere('slug', 'internal-tools');
    $response->assertRedirect("/admin/teams/{$team->id}/edit")->assertInertiaFlash('toast.type', 'success');
    expect($team->name)->toBe('Internal Tools');
});

it('rejects a slug that is not lowercase letters, digits and hyphens', function (string $slug) {
    actingAsAdmin();

    post('/admin/teams', ['name' => 'Payments', 'slug' => $slug])
        ->assertSessionHasErrors(['slug' => 'Chỉ dùng chữ thường, số và dấu gạch ngang.']);
})->with([
    'space' => 'pay ments',
    'uppercase' => 'Payments',
    'trailing hyphen' => 'payments-',
    'underscore' => 'pay_ments',
]);

it('rejects a slug another team already uses', function () {
    actingAsAdmin();
    Team::factory()->create(['slug' => 'payments']);

    post('/admin/teams', ['name' => 'Payments', 'slug' => 'payments'])->assertSessionHasErrors('slug');
});

it('keeps its own slug when a team is saved', function () {
    actingAsAdmin();
    $team = Team::factory()->create(['slug' => 'payments']);

    put("/admin/teams/{$team->id}", ['name' => 'Payments & Billing', 'slug' => 'payments'])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/admin/teams/{$team->id}/edit");

    expect($team->refresh()->name)->toBe('Payments & Billing');
});

it('shows only a masked webhook on the edit page', function () {
    actingAsAdmin();
    $team = Team::factory()->withGoogleChatWebhook()->create();
    Project::factory()->for($team)->create(['name' => 'Shop']);

    get("/admin/teams/{$team->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/edit')
            ->where('googleChat.masked_webhook_url', 'https://chat.googleapis.com/v1/spaces/DEMO/messages?key=••••')
            ->where('googleChat.status.label', 'Hoạt động')
            ->where('projects.data.0.name', 'Shop'))
        ->assertDontSee('demo-key')
        ->assertDontSee('demo-token');
});

it('sends a test message to the saved webhook', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/DEMO/messages*' => Http::response(['name' => 'spaces/DEMO/messages/1'])]);
    $team = Team::factory()->withGoogleChatWebhook()->create();

    post("/admin/teams/{$team->id}/test-message")
        ->assertRedirect("/admin/teams/{$team->id}/edit")
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Gửi thử thành công.']);

    Http::assertSentCount(1);
});

it('reports a failed test message', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/DEMO/messages*' => Http::response('Not found', 404)]);
    $team = Team::factory()->withGoogleChatWebhook()->create();

    post("/admin/teams/{$team->id}/test-message")
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Gửi thử thất bại');
});

it('saves a new webhook only after google chat accepts the test message', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/NEW/messages*' => Http::response(['name' => 'spaces/NEW/messages/1'])]);
    $team = Team::factory()->create();
    $webhookUrl = 'https://chat.googleapis.com/v1/spaces/NEW/messages?key=new-key&token=new-token';

    put("/admin/teams/{$team->id}/webhook", ['google_chat_webhook_url' => $webhookUrl])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Đã lưu webhook và gửi thử thành công.');

    expect($team->refresh()->google_chat_webhook_url)->toBe($webhookUrl);
    Http::assertSent(fn (Request $request): bool => $request->url() === $webhookUrl);
});

it('keeps the old webhook when the new one fails the test message', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/NEW/messages*' => Http::response('Not found', 404)]);
    $team = Team::factory()->withGoogleChatWebhook()->create();
    $savedWebhookUrl = $team->google_chat_webhook_url;

    put("/admin/teams/{$team->id}/webhook", ['google_chat_webhook_url' => 'https://chat.googleapis.com/v1/spaces/NEW/messages?key=new-key&token=new-token'])
        ->assertSessionHasErrors('google_chat_webhook_url');

    expect($team->refresh()->google_chat_webhook_url)->toBe($savedWebhookUrl);
});

it('rejects a webhook outside google chat without sending anything', function (string $webhookUrl) {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake();
    $team = Team::factory()->create();

    put("/admin/teams/{$team->id}/webhook", ['google_chat_webhook_url' => $webhookUrl])->assertSessionHasErrors('google_chat_webhook_url');

    Http::assertNothingSent();
})->with([
    'another host' => 'https://hooks.example.com/v1/spaces/AAA/messages',
    'plain http' => 'http://chat.googleapis.com/v1/spaces/AAA/messages',
    'not a url' => 'chat.googleapis.com',
]);

it('deletes a team without projects', function () {
    actingAsAdmin();
    $team = Team::factory()->create();

    delete("/admin/teams/{$team->id}")->assertRedirect('/admin/teams');

    assertModelMissing($team);
});

it('keeps a team that still owns projects', function () {
    actingAsAdmin();
    $team = Team::factory()->create();
    Project::factory()->for($team)->create();

    delete("/admin/teams/{$team->id}")->assertInertiaFlash('toast.type', 'error');

    assertModelExists($team);
    assertDatabaseHas(Project::class, ['team_id' => $team->id]);
});

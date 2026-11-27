<?php

use App\Enums\ChatNotificationKind;
use App\Enums\NotificationTarget;
use App\Models\ChatNotification;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    $this->team = Team::factory()->withGoogleChatWebhook()->create(['name' => 'Commerce']);
    $this->project = Project::factory()->for($this->team)->create(['name' => 'Shop']);
});

it('sends to the team by default and shows no webhook of its own', function () {
    actingAsAdmin();

    get("/admin/projects/{$this->project->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/edit')
            ->where('googleChat.target', 'team')
            ->where('googleChat.team', 'Commerce')
            ->where('googleChat.masked_webhook_url', null)
            ->where('googleChat.status.label', 'Chưa cấu hình'));

    expect($this->project->refresh()->ownsChatChannel())->toBeFalse();
});

it('saves its own webhook only after google chat accepts the test message, and sends there from then on', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/SHOP/messages*' => Http::response(['name' => 'spaces/SHOP/messages/1'])]);
    $webhookUrl = 'https://chat.googleapis.com/v1/spaces/SHOP/messages?key=shop-key&token=shop-token';

    put("/admin/projects/{$this->project->id}/webhook", ['google_chat_webhook_url' => $webhookUrl])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/admin/projects/{$this->project->id}/edit")
        ->assertInertiaFlash('toast.message', 'Đã lưu webhook và gửi thử thành công.');

    $this->project->refresh();
    $test = ChatNotification::sole();

    expect($this->project)->google_chat_webhook_url->toBe($webhookUrl)->notification_target->toBe(NotificationTarget::Project)
        ->and($test)->kind->toBe(ChatNotificationKind::Test)->project_id->toBe($this->project->id)->team_id->toBe($this->team->id)
        ->and($test->payload['text'])->toContain('project Shop (team Commerce)');
    Http::assertSent(fn (Request $request): bool => $request->url() === $webhookUrl);
});

it('keeps sending to the team when the new webhook fails the test message', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/SHOP/messages*' => Http::response('Not found', 404)]);

    put("/admin/projects/{$this->project->id}/webhook", ['google_chat_webhook_url' => 'https://chat.googleapis.com/v1/spaces/SHOP/messages?key=k&token=t'])
        ->assertSessionHasErrors('google_chat_webhook_url');

    expect($this->project->refresh())->google_chat_webhook_url->toBeNull()->notification_target->toBe(NotificationTarget::Team)
        ->and(ChatNotification::count())->toBe(0);
});

it('rejects a webhook outside google chat without sending anything', function () {
    actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake();

    put("/admin/projects/{$this->project->id}/webhook", ['google_chat_webhook_url' => 'https://hooks.example.com/v1/spaces/AAA/messages'])
        ->assertSessionHasErrors('google_chat_webhook_url');

    Http::assertNothingSent();
});

it('goes back to the team and keeps its own webhook to switch back to', function () {
    actingAsAdmin();
    $this->project->update(['notification_target' => NotificationTarget::Project, 'google_chat_webhook_url' => chatWebhook('SHOP')]);

    put("/admin/projects/{$this->project->id}/notification-target", ['notification_target' => 'team'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Thông báo của project sẽ gửi về team.');

    expect($this->project->refresh())->notification_target->toBe(NotificationTarget::Team)->google_chat_webhook_url->toBe(chatWebhook('SHOP'));

    put("/admin/projects/{$this->project->id}/notification-target", ['notification_target' => 'project'])->assertSessionHasNoErrors();

    expect($this->project->refresh()->ownsChatChannel())->toBeTrue();
});

it('needs a webhook of its own before sending there', function () {
    actingAsAdmin();

    put("/admin/projects/{$this->project->id}/notification-target", ['notification_target' => 'project'])
        ->assertSessionHasErrors(['notification_target' => 'Thêm webhook riêng cho project trước.']);

    expect($this->project->refresh()->ownsChatChannel())->toBeFalse();
});

it('shows only a masked webhook of its own with its status', function () {
    actingAsAdmin();
    $this->project->update(['notification_target' => NotificationTarget::Project, 'google_chat_webhook_url' => chatWebhook('SHOP')]);

    get("/admin/projects/{$this->project->id}/edit")
        ->assertInertia(fn (Assert $page) => $page
            ->where('googleChat.target', 'project')
            ->where('googleChat.masked_webhook_url', 'https://chat.googleapis.com/v1/spaces/SHOP/messages?key=••••')
            ->where('googleChat.status.label', 'Hoạt động'))
        ->assertDontSee('demo-key');

    get("/admin/projects/{$this->project->id}")
        ->assertInertia(fn (Assert $page) => $page->where('project.alert_channel.label', 'Webhook riêng của project · Hoạt động'));

    get('/admin/projects')
        ->assertInertia(fn (Assert $page) => $page->where('projects.data.0.has_own_webhook', true));
});

it('sends a test message to its saved webhook', function () {
    actingAsAdmin();
    fakeGoogleChat();
    $this->project->update(['notification_target' => NotificationTarget::Project, 'google_chat_webhook_url' => chatWebhook('SHOP')]);

    post("/admin/projects/{$this->project->id}/test-message")
        ->assertRedirect("/admin/projects/{$this->project->id}/edit")
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Gửi thử thành công.']);

    expect(chatMessagesTo('SHOP'))->toHaveCount(1)
        ->and(chatMessagesTo('DEMO'))->toBeEmpty();
});

it('keeps a failed message of the project on the overview when only the team webhook delivered since', function () {
    actingAsAdmin();
    config(['psm.alerts.admin_webhook' => chatWebhook('ADMIN')]);
    $this->project->update(['notification_target' => NotificationTarget::Project, 'google_chat_webhook_url' => chatWebhook('SHOP')]);
    ChatNotification::factory()->for($this->team)->create(['project_id' => $this->project->id, 'kind' => ChatNotificationKind::Immediate, 'failed_at' => now()->subHour(), 'last_error' => 'Google Chat trả lỗi HTTP 404.', 'created_at' => now()->subHours(2)]);
    ChatNotification::factory()->for($this->team)->sent()->create(['kind' => ChatNotificationKind::Immediate]);

    get('/admin')->assertInertia(fn (Assert $page) => $page
        ->has('delivery.destinations', 1)
        ->where('delivery.destinations.0.team.id', $this->team->id)
        ->where('delivery.destinations.0.count', 1)
        ->where('delivery.destinations.0.error', 'Google Chat trả lỗi HTTP 404.'));
});

it('only lets admins change where a project sends', function () {
    actingAs(User::factory()->create());

    put("/admin/projects/{$this->project->id}/webhook", ['google_chat_webhook_url' => chatWebhook('SHOP')])->assertForbidden();
    put("/admin/projects/{$this->project->id}/notification-target", ['notification_target' => 'team'])->assertForbidden();
    post("/admin/projects/{$this->project->id}/test-message")->assertForbidden();
});

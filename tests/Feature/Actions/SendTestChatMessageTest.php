<?php

use App\Actions\SendTestChatMessage;
use App\Enums\ChatNotificationKind;
use App\Models\ChatNotification;
use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const SAVED_WEBHOOK = 'https://chat.googleapis.com/v1/spaces/DEMO/messages?key=demo-key&token=demo-token';
const CANDIDATE_WEBHOOK = 'https://chat.googleapis.com/v1/spaces/NEW/messages?key=new-key&token=new-token';

it('sends a test message to the saved webhook and logs it as sent', function () {
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/DEMO/messages*' => Http::response(['name' => 'spaces/DEMO/messages/1'])]);
    $team = Team::factory()->withGoogleChatWebhook()->create(['name' => 'Commerce']);

    $error = app(SendTestChatMessage::class)->handle($team);

    expect($error)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === SAVED_WEBHOOK && str_contains($request['text'], 'Commerce'));
    expect($team->chatNotifications()->sole())
        ->kind->toBe(ChatNotificationKind::Test)
        ->sent_at->not->toBeNull()
        ->failed_at->toBeNull();
});

it('logs a failed attempt without leaking the webhook token when google chat rejects the message', function () {
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/DEMO/messages*' => Http::response('Not found', 404)]);
    $team = Team::factory()->withGoogleChatWebhook()->create();

    $error = app(SendTestChatMessage::class)->handle($team);

    $notification = $team->chatNotifications()->sole();
    expect($error)->toContain('404')->not->toContain('demo-token')
        ->and($notification->failed_at)->not->toBeNull()
        ->and($notification->sent_at)->toBeNull()
        ->and($notification->last_error)->toBe($error);
});

it('treats a redirect as a failure instead of following it', function () {
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/DEMO/messages*' => Http::response('', 302, ['Location' => 'https://attacker.example/collect'])]);
    $team = Team::factory()->withGoogleChatWebhook()->create();

    $error = app(SendTestChatMessage::class)->handle($team);

    expect($error)->toContain('302');
    Http::assertSentCount(1);
});

it('reports a connection failure without the webhook url', function () {
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/DEMO/messages*' => fn () => throw new ConnectionException('cURL error 28 for '.SAVED_WEBHOOK)]);
    $team = Team::factory()->withGoogleChatWebhook()->create();

    $error = app(SendTestChatMessage::class)->handle($team);

    expect($error)->not->toBeNull()->not->toContain('demo-token')
        ->and($team->chatNotifications()->sole()->last_error)->toBe($error);
});

it('does not log a failed candidate webhook so the saved webhook keeps its status', function () {
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/NEW/messages*' => Http::response('Forbidden', 403)]);
    $team = Team::factory()->withGoogleChatWebhook()->create();

    $error = app(SendTestChatMessage::class)->handle($team, CANDIDATE_WEBHOOK);

    expect($error)->toContain('403')
        ->and(ChatNotification::count())->toBe(0);
});

it('logs a successful candidate webhook as sent', function () {
    Http::preventStrayRequests();
    Http::fake(['https://chat.googleapis.com/v1/spaces/NEW/messages*' => Http::response(['name' => 'spaces/NEW/messages/1'])]);
    $team = Team::factory()->create();

    $error = app(SendTestChatMessage::class)->handle($team, CANDIDATE_WEBHOOK);

    expect($error)->toBeNull()
        ->and($team->chatNotifications()->sole()->sent_at)->not->toBeNull();
});

it('returns an error without sending when the team has no webhook', function () {
    Http::preventStrayRequests();
    Http::fake();
    $team = Team::factory()->create();

    $error = app(SendTestChatMessage::class)->handle($team);

    expect($error)->not->toBeNull()
        ->and(ChatNotification::count())->toBe(0);
    Http::assertNothingSent();
});

<?php

use App\Jobs\SendChatNotification;
use App\Models\ChatNotification;
use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function teamNotification(): ChatNotification
{
    return ChatNotification::factory()
        ->for(Team::factory()->state(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]))
        ->create(['payload' => ['text' => '[High] PHP 8.1 hết hỗ trợ']]);
}

function runJob(ChatNotification $notification, int $attempt = 1): SendChatNotification
{
    $job = (new SendChatNotification($notification))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;

    app()->call([$job, 'handle']);

    return $job;
}

it('posts the payload and marks the message sent', function () {
    fakeGoogleChat();
    $notification = teamNotification();

    runJob($notification);

    expect(chatMessagesTo('COMMERCE')->sole())->toBe(['text' => '[High] PHP 8.1 hết hỗ trợ'])
        ->and($notification->refresh())
        ->sent_at->not->toBeNull()
        ->attempts->toBe(1);
});

it('never sends a message twice', function () {
    fakeGoogleChat();
    $notification = teamNotification();

    runJob($notification);
    runJob($notification);

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});

it('retries a transient error with backoff', function (int $attempt, int $delay) {
    Http::fake([Team::GOOGLE_CHAT_WEBHOOK_PREFIX.'*' => Http::response('Unavailable', 503)]);
    $notification = teamNotification();

    runJob($notification, $attempt)->assertReleased(delay: $delay);

    expect($notification->refresh())
        ->failed_at->toBeNull()
        ->last_error->toBe('Google Chat trả lỗi HTTP 503.');
})->with([
    'first attempt' => [1, 60],
    'third attempt' => [3, 900],
]);

it('retries when google chat cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));
    $notification = teamNotification();

    runJob($notification)->assertReleased(delay: 60);

    expect($notification->refresh()->last_error)->toBe('Không kết nối được tới Google Chat.');
});

it('gives up after the last attempt and tells the admin space', function () {
    Http::fake([chatWebhook('COMMERCE') => Http::response('Unavailable', 503)]);
    fakeGoogleChat();
    $notification = teamNotification();

    runJob($notification, config('psm.alerts.tries'))->assertNotReleased();

    expect($notification->refresh()->failed_at)->not->toBeNull()
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Commerce', 'HTTP 503', 'PHP 8.1 hết hỗ trợ')->not->toContain('demo-token');
});

it('does not retry when google chat rejects the webhook', function () {
    Http::fake([chatWebhook('COMMERCE') => Http::response('Not found', 404)]);
    fakeGoogleChat();
    $notification = teamNotification();

    runJob($notification)->assertNotReleased();

    expect($notification->refresh()->failed_at)->not->toBeNull()
        ->and(chatMessagesTo('ADMIN'))->toHaveCount(1);
});

it('logs instead of alerting when the admin space itself is not configured', function () {
    Http::preventStrayRequests();
    config(['psm.alerts.admin_webhook' => null]);
    Log::spy();
    $notification = ChatNotification::factory()->forAdmin()->create();

    runJob($notification);

    expect($notification->refresh())
        ->failed_at->not->toBeNull()
        ->last_error->toBe('Chưa cấu hình PSM_ADMIN_CHAT_WEBHOOK.')
        ->and(ChatNotification::count())->toBe(1);
    Log::shouldHaveReceived('error')->once();
});

it('ignores an admin webhook outside google chat', function () {
    Http::preventStrayRequests();
    config(['psm.alerts.admin_webhook' => 'https://attacker.example/hook']);
    $notification = ChatNotification::factory()->forAdmin()->create();

    runJob($notification);

    expect($notification->refresh()->failed_at)->not->toBeNull();
});

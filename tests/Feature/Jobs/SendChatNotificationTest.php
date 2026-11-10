<?php

use App\Actions\SendPreNoticeAlerts;
use App\Enums\IncidentImpact;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Jobs\SendChatNotification;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\IncidentTeam;
use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

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

it('does not send a queued incident alert once an admin ruled the team out, nor tell the admin space', function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $team = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $notice = Advisory::factory()->preNotice(Technology::Php)->create(['severity' => Severity::High, 'aliases' => ['CVE-2026-111497'], 'published_at' => '2026-10-20 20:32:00']);
    runtimeAt($team, 'shop-api', Technology::Php, '8.3.35')->update(['version' => '8.3.35', 'branch' => '8.3']);
    Queue::fake();
    app(SendPreNoticeAlerts::class)->handle();
    $incident = $notice->refresh()->incident;
    $opener = ChatNotification::findOrFail(IncidentTeam::where('incident_id', $incident->id)->sole()->opening_notification_id);
    $opener->update(['attempts' => 1, 'last_error' => 'Google Chat trả lỗi HTTP 503.']);
    assessIncident($incident, $team, IncidentImpact::No);

    runJob($opener)->assertNotReleased();

    expect(Http::recorded())->toHaveCount(0)
        ->and($opener->refresh())
        ->sent_at->toBeNull()
        ->failed_at->not->toBeNull()
        ->attempts->toBe(1)
        ->last_error->toBe('Không gửi: admin đã đánh giá sự kiện không ảnh hưởng tới team (Không).');
});

it('never sends a message twice', function () {
    fakeGoogleChat();
    $notification = teamNotification();

    runJob($notification);
    runJob($notification);

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});

it('retries a transient error with backoff, counting only the HTTP attempts', function (int $attempt, int $delay) {
    Http::fake([Team::GOOGLE_CHAT_WEBHOOK_PREFIX.'*' => Http::response('Unavailable', 503)]);
    $notification = teamNotification();
    $notification->update(['attempts' => $attempt - 1]);

    runJob($notification, $attempt + 10)->assertReleased(delay: $delay);

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
    Http::fake([chatWebhook('COMMERCE').'*' => Http::response('Unavailable', 503)]);
    fakeGoogleChat();
    $notification = teamNotification();
    $notification->update(['attempts' => config('psm.alerts.tries') - 1]);

    runJob($notification)->assertNotReleased();

    expect($notification->refresh()->failed_at)->not->toBeNull()
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Commerce', 'HTTP 503', 'PHP 8.1 hết hỗ trợ')->not->toContain('demo-token');
});

it('does not retry when google chat rejects the webhook', function () {
    Http::fake([chatWebhook('COMMERCE').'*' => Http::response('Not found', 404)]);
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

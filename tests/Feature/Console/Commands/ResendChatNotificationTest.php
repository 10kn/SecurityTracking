<?php

use App\Models\ChatNotification;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

beforeEach(function () {
    fakeGoogleChat();
});

it('sends a message that failed for good again', function () {
    $notification = ChatNotification::factory()
        ->for(Team::factory()->state(['google_chat_webhook_url' => chatWebhook('COMMERCE')]))
        ->failed()
        ->create(['payload' => ['text' => '[Critical] Upcoming Critical PHP Security Release']]);

    artisan('psm:resend-notification', ['notification' => $notification->id])->assertSuccessful();

    expect(chatMessagesTo('COMMERCE')->sole()['text'])->toBe('[Critical] Upcoming Critical PHP Security Release')
        ->and($notification->refresh())
        ->sent_at->not->toBeNull()
        ->failed_at->toBeNull()
        ->last_error->toBeNull();
});

it('refuses messages that did not fail', function () {
    $notification = ChatNotification::factory()->sent()->create();

    artisan('psm:resend-notification', ['notification' => $notification->id])->assertFailed();
    artisan('psm:resend-notification', ['notification' => 999999])->assertFailed();

    expect(Http::recorded())->toHaveCount(0);
});

it('keeps the message failed when it cannot be queued', function () {
    $notification = ChatNotification::factory()->failed()->create();
    Queue::shouldReceive('connection->push')->andThrow(new RuntimeException('Redis is down'));

    expect(fn () => artisan('psm:resend-notification', ['notification' => $notification->id])->run())->toThrow(RuntimeException::class);

    expect($notification->refresh()->failed_at)->not->toBeNull();
});

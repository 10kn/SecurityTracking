<?php

use App\Actions\MergeIncidents;
use App\Actions\SendIncidentUpdate;
use App\Actions\SendPreNoticeAlerts;
use App\Enums\ChatNotificationKind;
use App\Enums\ChatThreadRole;
use App\Enums\IncidentImpact;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\Team;
use App\Models\User;
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

it('sends a failed reply of an incident thread into the thread, or opens it when its opener failed too', function (bool $isOpenerSent, string $text) {
    $team = Team::factory()->create(['google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    [$thread] = IncidentTeam::lockFor(Incident::factory()->create(['technology' => Technology::Php]), $team);
    $thread->moveTo(chatWebhook('COMMERCE'));
    $opener = ChatNotification::factory()->for($team)->create([
        ...$thread->threadAttributes(ChatThreadRole::Opener),
        ...($isOpenerSent ? ['sent_at' => now()] : ['failed_at' => now()]),
    ]);
    $thread->update(['opening_notification_id' => $opener->id, 'thread_name' => $isOpenerSent ? 'spaces/COMMERCE/threads/T1' : null]);
    $reply = ChatNotification::factory()->for($team)->failed()->create([
        ...$thread->threadAttributes(ChatThreadRole::Reply),
        'sent_as' => ChatThreadRole::Opener,
        'payload' => ['text' => 'reply'],
        'opening_payload' => ['text' => 'full card'],
    ]);

    artisan('psm:resend-notification', ['notification' => $reply->id])->assertSuccessful();

    expect(chatMessagesTo('COMMERCE')->sole())->toMatchArray(['text' => $text, 'thread' => ['threadKey' => $thread->thread_key]])
        ->and($reply->refresh()->sent_at)->not->toBeNull();
})->with([
    'opener sent' => [true, 'reply'],
    'opener failed' => [false, 'full card'],
]);

/**
 * A PHP pre-notice told to a Linux team without a webhook: the card that opens its thread failed, and the admin fixed the
 * webhook since. Returns the incident, the team and the failed opener.
 *
 * @return array{0: Incident, 1: Team, 2: ChatNotification}
 */
function failedIncidentOpener(): array
{
    test()->travelTo('2026-10-20 21:00:00');
    $team = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => null]);
    $notice = Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'PHP 8.3.36 rebuilt for Windows (Brotli)',
        'severity' => Severity::High,
        'aliases' => ['CVE-2026-111497'],
        'published_at' => '2026-10-20 20:32:00',
    ]);
    runtimeAt($team, 'shop-api', Technology::Php, '8.3.35')->update(['version' => '8.3.35', 'branch' => '8.3']);
    app(SendPreNoticeAlerts::class)->handle();
    $incident = $notice->refresh()->incident;
    $opener = ChatNotification::findOrFail(IncidentTeam::where('incident_id', $incident->id)->sole()->opening_notification_id);
    $team->update(['google_chat_webhook_url' => chatWebhook('COMMERCE')]);

    expect($opener->failed_at)->not->toBeNull();

    return [$incident, $team, $opener];
}

it('does not send again an incident alert to a team an admin ruled out', function () {
    [$incident, $team, $opener] = failedIncidentOpener();
    assessIncident($incident, $team, IncidentImpact::No);

    artisan('psm:resend-notification', ['notification' => $opener->id])
        ->expectsOutputToContain('admin đã đánh giá sự kiện không ảnh hưởng tới team (Không)')
        ->assertFailed();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(0)
        ->and($opener->refresh())
        ->sent_at->toBeNull()
        ->failed_at->not->toBeNull()
        ->last_error->toBe('Không gửi: admin đã đánh giá sự kiện không ảnh hưởng tới team (Không).');
});

it('sends an incident alert again once the admin ruling no longer holds or says the team is affected', function (IncidentImpact $impact, bool $isExposureGrown) {
    [$incident, $team, $opener] = failedIncidentOpener();
    assessIncident($incident, $team, $impact);

    if ($isExposureGrown) {
        runtimeAt($team, 'booking-api', Technology::Php, '8.4.1')->update(['version' => '8.4.1', 'branch' => '8.4']);
    }

    artisan('psm:resend-notification', ['notification' => $opener->id])->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1)
        ->and($opener->refresh()->sent_at)->not->toBeNull();
})->with([
    'affected' => [IncidentImpact::Yes, false],
    'ruled out, then another service runs the technology' => [IncidentImpact::No, true],
]);

it('still sends an admin update of the thread again after the team was ruled out', function () {
    [$incident, $team, $opener] = failedIncidentOpener();
    $team->update(['google_chat_webhook_url' => null]);
    app(SendIncidentUpdate::class)->handle($incident, 'Windows builds only: Linux servers need nothing.');
    $update = ChatNotification::where('kind', ChatNotificationKind::IncidentUpdate)->sole();
    $team->update(['google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    assessIncident($incident, $team, IncidentImpact::No);

    expect($update->failed_at)->not->toBeNull();

    artisan('psm:resend-notification', ['notification' => $update->id])->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1)
        ->and($update->refresh()->sent_at)->not->toBeNull();
});

it('reads the ruling on the incident a merged one went into', function () {
    [$incident, $team, $opener] = failedIncidentOpener();
    $into = Incident::factory()->create(['technology' => Technology::Php, 'title' => 'PHP security release', 'first_published_at' => now()]);
    app(MergeIncidents::class)->handle($incident, $into, User::factory()->admin()->create());
    assessIncident($into, $team, IncidentImpact::No);

    artisan('psm:resend-notification', ['notification' => $opener->id])->assertFailed();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(0);
});

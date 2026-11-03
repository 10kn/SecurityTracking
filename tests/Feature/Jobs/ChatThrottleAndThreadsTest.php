<?php

use App\Enums\ChatNotificationKind;
use App\Enums\ChatThreadRole;
use App\Enums\Technology;
use App\Jobs\SendChatNotification;
use App\Models\ChatNotification;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\Team;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->travelTo('2026-10-21 09:00:00');
    fakeGoogleChat();
    $this->team = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
});

/**
 * Runs the job as a queue worker does, with its releases faked.
 */
function workJob(ChatNotification $notification): SendChatNotification
{
    $job = (new SendChatNotification($notification))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    return $job;
}

/**
 * A message of the team's thread of a new incident, queued as `$role`.
 *
 * @param  array<string, mixed>  $attributes
 */
function threadMessage(Team $team, ChatThreadRole $role, array $attributes = []): ChatNotification
{
    $incident = Incident::factory()->create(['technology' => Technology::Php]);
    [$thread] = IncidentTeam::lockFor($incident, $team);
    $thread->moveTo(chatWebhook('COMMERCE'));

    $notification = ChatNotification::factory()->for($team)->create([
        'kind' => ChatNotificationKind::PreNotice,
        'payload' => ['text' => $role === ChatThreadRole::Reply ? 'reply' : 'opener'],
        'opening_payload' => $role === ChatThreadRole::Reply ? ['text' => 'full card'] : null,
        ...$thread->threadAttributes($role),
        ...$attributes,
    ]);
    $thread->recordQueued($notification);

    return $notification;
}

it('spaces the messages of one space at least a second apart, whatever their kind, and goes back to the queue meanwhile', function () {
    $first = ChatNotification::factory()->for($this->team)->create(['kind' => ChatNotificationKind::Digest]);
    $second = ChatNotification::factory()->for($this->team)->create(['kind' => ChatNotificationKind::Immediate]);
    $admin = ChatNotification::factory()->forAdmin()->create();

    workJob($first)->assertNotReleased();
    workJob($second)->assertReleased(delay: 1);
    workJob($admin)->assertNotReleased();

    expect($second->refresh())->sent_at->toBeNull()->attempts->toBe(0)
        ->and(chatRequestsTo('COMMERCE'))->toHaveCount(1);

    $this->travel(1)->second();

    workJob($second)->assertNotReleased();

    expect(chatRequestsTo('COMMERCE'))->toHaveCount(2);
});

it('goes back to the queue while another message of the space is being sent', function () {
    $notification = ChatNotification::factory()->for($this->team)->create();
    $lock = Cache::lock('chat-space:COMMERCE', 30);
    $lock->get();

    workJob($notification)->assertReleased(delay: 1);

    $lock->release();

    workJob($notification)->assertNotReleased();

    expect($notification->refresh()->sent_at)->not->toBeNull();
});

it('waits for the turn of the space instead when it runs without a queue worker', function () {
    $first = ChatNotification::factory()->for($this->team)->create();
    $second = ChatNotification::factory()->for($this->team)->create();

    SendChatNotification::dispatchSync($first);
    SendChatNotification::dispatchSync($second);

    expect($second->refresh()->sent_at)->not->toBeNull()
        ->and(chatRequestsTo('COMMERCE'))->toHaveCount(2);
    Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds === 1000.0, 1);
});

it('retries until a fixed deadline set when it is queued', function () {
    config(['psm.alerts.retry_hours' => 6]);

    expect((new SendChatNotification(ChatNotification::factory()->create()))->retryUntil()->format('Y-m-d H:i'))->toBe('2026-10-21 15:00');
});

it('honours the Retry-After of a 429 for the whole space, with a little jitter', function () {
    Http::swap(new Factory);
    Http::fake([chatWebhook('COMMERCE').'*' => Http::response('Too many', 429, ['Retry-After' => '30'])]);
    $notification = ChatNotification::factory()->for($this->team)->create();
    $other = ChatNotification::factory()->for($this->team)->create();

    $job = workJob($notification);

    expect($job->job->isReleased())->toBeTrue()
        ->and($job->job->releaseDelay)->toBeGreaterThanOrEqual(30)->toBeLessThanOrEqual(33)
        ->and($notification->refresh())->attempts->toBe(1)->failed_at->toBeNull();

    $this->travel(10)->seconds();

    workJob($other)->assertReleased(delay: 20);
});

it('sends no request id on any try, since google chat webhooks reject it', function () {
    Http::swap(new Factory);
    $failing = true;
    Http::fake([chatWebhook('COMMERCE').'*' => function () use (&$failing) {
        return $failing ? Http::response('Unavailable', 503) : Http::response(['name' => 'spaces/COMMERCE/messages/1']);
    }]);
    $notification = ChatNotification::factory()->for($this->team)->create();

    workJob($notification)->assertReleased(delay: 60);
    $this->travel(2)->minutes();
    $failing = false;
    workJob($notification)->assertNotReleased();

    expect(chatRequestsTo('COMMERCE')->map(fn (Request $request): string => $request->url())->all())->toBe([chatWebhook('COMMERCE'), chatWebhook('COMMERCE')])
        ->and($notification->refresh())->attempts->toBe(2)->sent_at->not->toBeNull()->google_message_name->toBe('spaces/COMMERCE/messages/1');
});

it('replies in the thread once its opener was delivered with the thread', function () {
    $opener = threadMessage($this->team, ChatThreadRole::Opener);
    $reply = ChatNotification::factory()->for($this->team)->create([
        'kind' => ChatNotificationKind::PreNotice,
        'payload' => ['text' => 'reply'],
        'opening_payload' => ['text' => 'full card'],
        ...IncidentTeam::sole()->threadAttributes(ChatThreadRole::Reply),
    ]);

    workJob($reply)->assertReleased(delay: 15);
    workJob($opener)->assertNotReleased();
    $this->travel(15)->seconds();
    workJob($reply)->assertNotReleased();

    [$sentOpener, $sentReply] = chatMessagesTo('COMMERCE')->all();
    expect($sentOpener['text'])->toBe('opener')
        ->and($sentReply)->toMatchArray(['text' => 'reply', 'thread' => ['threadKey' => IncidentTeam::sole()->thread_key]])
        ->and($reply->refresh()->sent_as)->toBe(ChatThreadRole::Reply)
        ->and(IncidentTeam::sole()->opening_notification_id)->toBe($opener->id);
});

it('opens the thread with its full card when the opener is still not sent after the wait', function () {
    $opener = threadMessage($this->team, ChatThreadRole::Opener);
    $reply = ChatNotification::factory()->for($this->team)->create([
        'kind' => ChatNotificationKind::PreNotice,
        'payload' => ['text' => 'reply'],
        'opening_payload' => ['text' => 'full card'],
        ...IncidentTeam::sole()->threadAttributes(ChatThreadRole::Reply),
    ]);

    workJob($reply)->assertReleased();
    $this->travel(10)->minutes();
    workJob($reply)->assertNotReleased();

    expect(chatMessagesTo('COMMERCE')->sole()['text'])->toBe('full card')
        ->and($reply->refresh()->sent_as)->toBe(ChatThreadRole::Opener)
        ->and(IncidentTeam::sole())->opening_notification_id->toBe($reply->id)->thread_name->not->toBeNull();

    $this->travel(1)->minute();
    workJob($opener)->assertNotReleased();

    expect(chatMessagesTo('COMMERCE')->last()['thread']['threadKey'])->toBe(IncidentTeam::sole()->thread_key)
        ->and(IncidentTeam::sole()->opening_notification_id)->toBe($reply->id);
});

it('opens the thread with its full card right away when the opener failed, and keeps that choice for its retries', function () {
    threadMessage($this->team, ChatThreadRole::Opener, ['failed_at' => now(), 'last_error' => 'HTTP 404']);
    $reply = ChatNotification::factory()->for($this->team)->create([
        'kind' => ChatNotificationKind::PreNotice,
        'payload' => ['text' => 'reply'],
        'opening_payload' => ['text' => 'full card'],
        ...IncidentTeam::sole()->threadAttributes(ChatThreadRole::Reply),
    ]);
    Http::swap(new Factory);
    Http::fake([chatWebhook('COMMERCE').'*' => Http::sequence()->push('Unavailable', 503)->push(['name' => 'spaces/COMMERCE/messages/9', 'thread' => ['name' => 'spaces/COMMERCE/threads/T9']])]);

    workJob($reply)->assertReleased(delay: 60);

    expect($reply->refresh()->sent_as)->toBe(ChatThreadRole::Opener);

    IncidentTeam::sole()->update(['opening_notification_id' => null]);
    $this->travel(2)->minutes();
    workJob($reply)->assertNotReleased();

    expect(chatMessagesTo('COMMERCE')->pluck('text')->all())->toBe(['full card', 'full card'])
        ->and(IncidentTeam::sole()->thread_name)->toBe('spaces/COMMERCE/threads/T9');
});

it('marks a message sent without thread metadata as sent, leaves the thread to be opened again and tells the admin once a day', function () {
    Http::swap(new Factory);
    Http::fake([
        chatWebhook('COMMERCE').'*' => Http::response(['name' => 'spaces/COMMERCE/messages/1']),
        chatWebhook('ADMIN').'*' => Http::response(['name' => 'spaces/ADMIN/messages/1']),
    ]);
    $opener = threadMessage($this->team, ChatThreadRole::Opener);
    $other = threadMessage($this->team, ChatThreadRole::Opener);

    SendChatNotification::dispatchSync($opener);
    SendChatNotification::dispatchSync($other);

    expect($opener->refresh())->sent_at->not->toBeNull()->google_thread_name->toBeNull()
        ->and(IncidentTeam::where('opening_notification_id', $opener->id)->sole())->thread_name->toBeNull()
        ->and(IncidentTeam::where('opening_notification_id', $opener->id)->sole()->nextRole())->toBe(ChatThreadRole::Opener)
        ->and(chatMessagesTo('ADMIN'))->toHaveCount(1)
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Google Chat không trả về thread', 'Commerce');
});

it('opens a new thread in the new space when the team moved before the message was sent', function () {
    $reply = threadMessage($this->team, ChatThreadRole::Opener);
    $this->team->update(['google_chat_webhook_url' => chatWebhook('COMMERCE2')]);

    workJob($reply)->assertNotReleased();

    $thread = IncidentTeam::sole();
    expect($thread)->generation->toBe(2)->space->toBe('COMMERCE2')->opening_notification_id->toBe($reply->id)
        ->and($reply->refresh())->generation->toBe(2)->thread_key->toBe($thread->thread_key)
        ->and(chatMessagesTo('COMMERCE2')->sole()['thread']['threadKey'])->toBe($thread->thread_key);
});

it('sends a reply whose team moved after a failed try as the full card that opens the new thread', function () {
    threadMessage($this->team, ChatThreadRole::Opener, ['sent_at' => now()]);
    IncidentTeam::sole()->update(['thread_name' => 'spaces/COMMERCE/threads/T1']);
    $reply = ChatNotification::factory()->for($this->team)->create([
        'kind' => ChatNotificationKind::PreNotice,
        'payload' => ['text' => 'reply'],
        'opening_payload' => ['text' => 'full card'],
        ...IncidentTeam::sole()->threadAttributes(ChatThreadRole::Reply),
    ]);
    Http::swap(new Factory);
    Http::fake([chatWebhook('COMMERCE').'*' => Http::response('Unavailable', 503)]);
    fakeGoogleChat();

    workJob($reply)->assertReleased(delay: 60);

    expect($reply->refresh()->sent_as)->toBe(ChatThreadRole::Reply);

    $this->team->update(['google_chat_webhook_url' => chatWebhook('COMMERCE2')]);
    $this->travel(2)->minutes();
    workJob($reply)->assertNotReleased();

    $thread = IncidentTeam::sole();
    expect(chatMessagesTo('COMMERCE2')->sole())->toMatchArray(['text' => 'full card', 'thread' => ['threadKey' => $thread->thread_key]])
        ->and($thread)->generation->toBe(2)->opening_notification_id->toBe($reply->id)
        ->and($reply->refresh())->sent_as->toBe(ChatThreadRole::Opener)->generation->toBe(2);
});

it('sends a message of a merged incident to the thread of the incident it went into', function () {
    $from = threadMessage($this->team, ChatThreadRole::Opener, ['sent_at' => now()]);
    $into = threadMessage($this->team, ChatThreadRole::Opener, ['sent_at' => now()]);
    $fromThread = IncidentTeam::find($from->incident_team_id);
    $intoThread = IncidentTeam::find($into->incident_team_id);
    $intoThread->update(['thread_name' => 'spaces/COMMERCE/threads/INTO']);
    $fromThread->update(['merged_into_id' => $intoThread->id]);
    $reply = ChatNotification::factory()->for($this->team)->create([
        'kind' => ChatNotificationKind::PreNotice,
        'payload' => ['text' => 'reply'],
        'opening_payload' => ['text' => 'full card'],
        ...$fromThread->threadAttributes(ChatThreadRole::Reply),
    ]);

    workJob($reply)->assertNotReleased();

    expect(chatMessagesTo('COMMERCE')->sole())->toMatchArray(['text' => 'reply', 'thread' => ['threadKey' => $intoThread->thread_key]])
        ->and($reply->refresh())->incident_team_id->toBe($intoThread->id)->sent_as->toBe(ChatThreadRole::Reply);
});

it('sleeps for the turn of the space without holding its lock when it runs without a queue worker', function () {
    $first = ChatNotification::factory()->for($this->team)->create();
    $second = ChatNotification::factory()->for($this->team)->create();
    $isLockFree = null;
    Sleep::whenFakingSleep(function () use (&$isLockFree): void {
        $lock = Cache::lock('chat-space:COMMERCE', 30);
        $isLockFree = $lock->get();
        $lock->release();
    });

    SendChatNotification::dispatchSync($first);
    SendChatNotification::dispatchSync($second);

    expect($isLockFree)->toBeTrue()
        ->and($second->refresh()->sent_at)->not->toBeNull();
});

it('marks a message failed, to be sent again, when the space stays busy for a job that cannot be released', function () {
    $notification = ChatNotification::factory()->for($this->team)->create();
    Cache::lock('chat-space:COMMERCE', 30)->get();

    SendChatNotification::dispatchSync($notification);

    expect($notification->refresh())
        ->sent_at->toBeNull()
        ->failed_at->not->toBeNull()
        ->last_error->toBe('Space Google Chat đang bận, chưa gửi được.')
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('psm:resend-notification');
});

it('takes the lock of the technology\'s incidents before locking a thread row', function () {
    $notification = threadMessage($this->team, ChatThreadRole::Opener);
    DB::enableQueryLog();

    workJob($notification)->assertNotReleased();

    $queries = collect(DB::getQueryLog());
    $lock = $queries->search(fn (array $query): bool => str_contains($query['query'], 'pg_advisory_xact_lock') && $query['bindings'] === [crc32('incidents:php')]);
    expect($lock)->toBeInt()
        ->and($lock)->toBeLessThan($queries->search(fn (array $query): bool => str_contains($query['query'], 'from "incident_team"') && str_contains($query['query'], 'for update')));
});

it('goes back to the queue instead of waiting long for an alert run that holds the incidents', function () {
    config(['psm.alerts.incident_lock_wait_ms' => 100, 'database.connections.lock_holder' => config('database.connections.pgsql')]);
    $notification = threadMessage($this->team, ChatThreadRole::Opener);
    $holder = DB::connection('lock_holder');
    $holder->select('select pg_advisory_lock(?)', [crc32('incidents:php')]);

    try {
        workJob($notification)->assertReleased(delay: 5);
    } finally {
        $holder->select('select pg_advisory_unlock(?)', [crc32('incidents:php')]);
        DB::purge('lock_holder');
    }

    expect($notification->refresh())->sent_at->toBeNull()->attempts->toBe(0)->sent_as->toBeNull()
        ->and(chatRequestsTo('COMMERCE'))->toHaveCount(0)
        ->and(Cache::lock('chat-space:COMMERCE', 1)->get(fn (): bool => true))->toBeTrue();

    workJob($notification)->assertNotReleased();

    expect($notification->refresh()->sent_at)->not->toBeNull();
});

it('counts a wait for the incidents as a try for the space when it runs without a queue worker', function () {
    config(['psm.alerts.incident_lock_wait_ms' => 50, 'database.connections.lock_holder' => config('database.connections.pgsql')]);
    $notification = threadMessage($this->team, ChatThreadRole::Opener);
    $holder = DB::connection('lock_holder');
    $holder->select('select pg_advisory_lock(?)', [crc32('incidents:php')]);

    try {
        SendChatNotification::dispatchSync($notification);
    } finally {
        $holder->select('select pg_advisory_unlock(?)', [crc32('incidents:php')]);
        DB::purge('lock_holder');
    }

    expect($notification->refresh())
        ->sent_at->toBeNull()
        ->failed_at->not->toBeNull()
        ->last_error->toBe('Space Google Chat đang bận, chưa gửi được.');
});

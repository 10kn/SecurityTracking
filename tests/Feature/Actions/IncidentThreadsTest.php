<?php

use App\Actions\AssignNoticeToIncident;
use App\Actions\MergeIncidents;
use App\Actions\SendPreNoticeAlerts;
use App\Actions\SplitNotice;
use App\Enums\ChatNotificationKind;
use App\Enums\ChatThreadRole;
use App\Enums\IncidentAlertReason;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamNotice;
use App\Models\Runtime;
use App\Models\RuntimeRelease;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $this->commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->preNotice = Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'Upcoming Critical PHP Security Release',
        'severity' => Severity::High,
        'aliases' => ['CVE-2026-1001'],
        'url' => 'https://www.php.net/archive/2026.php#2026-10-20-1',
        'published_at' => '2026-10-20 20:32:00',
    ]);
    $this->runtime = threadRuntime($this->commerce, 'shop-api', '8.3.35');
});

/**
 * A PHP runtime of the team's service on a known branch.
 */
function threadRuntime(Team $team, string $service, string $version): Runtime
{
    $runtime = runtimeAt($team, $service, Technology::Php, $version);
    $runtime->update(['version' => $version, 'branch' => implode('.', array_slice(explode('.', $version), 0, 2))]);

    return $runtime;
}

function sendPreNotices(): int
{
    return app(SendPreNoticeAlerts::class)->handle();
}

it('opens one thread per incident and team with the first card and records the names google chat returns', function () {
    expect(sendPreNotices())->toBe(1);

    $request = chatRequestsTo('COMMERCE')->sole();
    $thread = IncidentTeam::sole();
    $notification = ChatNotification::sole();

    expect($request->data()['thread']['threadKey'])->toBe($thread->thread_key)
        ->and($request->url())->toContain('&messageReplyOption=REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD', "&requestId={$notification->request_id}", 'key=demo-key&token=demo-token')
        ->and($thread->thread_key)->toStartWith('psm-')->toEndWith("-incident-{$this->preNotice->refresh()->incident_id}-team-{$this->commerce->id}-g1")
        ->and($thread)->space->toBe('COMMERCE')->generation->toBe(1)->opening_notification_id->toBe($notification->id)
        ->and($thread->thread_name)->toStartWith('spaces/COMMERCE/threads/')
        ->and($notification)
        ->thread_role->toBe(ChatThreadRole::Opener)
        ->sent_as->toBe(ChatThreadRole::Opener)
        ->google_message_name->toStartWith('spaces/COMMERCE/messages/')
        ->google_thread_name->toBe($thread->thread_name);
});

it('replies in the thread with a compact card when the severity rises', function () {
    sendPreNotices();

    $this->travel(1)->hour();
    $this->preNotice->update(['severity' => Severity::Critical]);

    expect(sendPreNotices())->toBe(1);

    [$opener, $reply] = chatMessagesTo('COMMERCE')->all();
    expect($reply['thread']['threadKey'])->toBe($opener['thread']['threadKey'])
        ->and($reply['text'])->toBe('[Critical] Upcoming Critical PHP Security Release')
        ->and(chatJson($reply))->toContain('Cập nhật · Commerce', 'Mức độ tăng từ High lên Critical.', 'Xem lại mức ưu tiên xử lý theo mức độ mới.', 'shop-api', 'https://www.php.net/archive/2026.php#2026-10-20-1')
        ->not->toContain('Cảnh báo chuẩn bị')
        ->and(ChatNotification::latest('id')->first())
        ->thread_role->toBe(ChatThreadRole::Reply)
        ->opening_payload->not->toBeNull()
        ->and(chatJson(ChatNotification::latest('id')->first()->opening_payload))->toContain('Cảnh báo chuẩn bị · Commerce', 'Mức độ tăng từ High lên Critical.');
});

it('sends nothing for more CVEs, a new planned release or reworded text', function () {
    sendPreNotices();

    $this->travel(1)->hour();
    $this->preNotice->update(['aliases' => ['CVE-2026-1001', 'CVE-2026-1002'], 'planned_release_at' => '2026-10-22 14:00:00', 'localized_summary' => 'Sửa lại câu chữ.']);
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP security advisory', 'severity' => Severity::High, 'aliases' => ['CVE-2026-1001'], 'published_at' => now()]);

    expect(sendPreNotices())->toBe(0)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});

it('replies once when the vendor says the incident is exploited', function () {
    sendPreNotices();

    $this->travel(1)->hour();
    Advisory::factory()->preNotice(Technology::Php)->exploited('CVE-2026-1001 is being actively exploited in the wild.')->create(['title' => 'PHP security advisory', 'severity' => Severity::High, 'aliases' => ['CVE-2026-1001'], 'published_at' => now()]);

    expect(sendPreNotices())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Hãng xác nhận lỗ hổng đang bị khai thác: “CVE-2026-1001 is being actively exploited in the wild.”', 'Lỗ hổng đang bị khai thác: ưu tiên nâng cấp hoặc giảm thiểu ngay.')
        ->and(IncidentTeam::sole())
        ->exploited_told_at->not->toBeNull()
        ->last_notification_id->toBe(ChatNotification::latest('id')->value('id'));

    $this->travel(1)->hour();

    expect(sendPreNotices())->toBe(0);
});

it('counts exploitation the first card already said as told', function () {
    $this->preNotice->update(['is_exploited' => true, 'exploited_basis' => ['method' => 'keyword', 'excerpt' => 'It is actively exploited.', 'content_hash' => 'x']]);

    sendPreNotices();
    $this->travel(1)->hour();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Hãng xác nhận lỗ hổng đang bị khai thác: “It is actively exploited.”')
        ->and(sendPreNotices())->toBe(0);
});

it('replies when the vendor confirms a branch the team runs, also one it had ruled out before', function () {
    threadRuntime($this->commerce, 'shop-admin', '8.4.26');
    $this->preNotice->update(['affected_branches' => ['8.3']]);
    sendPreNotices();

    $this->travel(1)->hour();
    $this->preNotice->update(['affected_branches' => ['8.3', '8.5']]);

    expect(sendPreNotices())->toBe(0);

    $this->preNotice->update(['affected_branches' => ['8.3', '8.4']]);

    expect(sendPreNotices())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Hãng xác nhận thêm nhánh bị ảnh hưởng: 8.4.', 'PHP 8.4.26 · Phạm vi: đã xác nhận')
        ->and(IncidentTeam::sole()->told_confirmed_branches)->toBe(['8.3', '8.4']);

    $this->preNotice->update(['affected_branches' => ['8.3'], 'unaffected_branches' => ['8.4']]);

    expect(sendPreNotices())->toBe(0)
        ->and(IncidentTeam::sole()->told_confirmed_branches)->toBe(['8.3']);

    $this->preNotice->update(['affected_branches' => ['8.3', '8.4'], 'unaffected_branches' => []]);

    expect(sendPreNotices())->toBe(1)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(3);
});

it('makes one reply of several changes in one run', function () {
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    sendPreNotices();

    $this->travel(1)->hour();
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    $this->preNotice->update(['severity' => Severity::Critical, 'affected_branches' => ['8.3'], 'is_exploited' => true, 'exploited_basis' => ['method' => 'keyword', 'excerpt' => 'It is actively exploited.', 'content_hash' => 'x']]);

    expect(sendPreNotices())->toBe(1);

    $reply = chatMessagesTo('COMMERCE')->last();
    expect($reply['text'])->toBe('[Critical] Đã có bản vá, nâng lên 8.3.36')
        ->and(chatJson($reply))->toContain(
            'Hãng đã xác nhận bản vá 8.3.36.',
            'Hãng xác nhận lỗ hổng đang bị khai thác',
            'Hãng xác nhận thêm nhánh bị ảnh hưởng: 8.3.',
            'Mức độ tăng từ High lên Critical.',
            'PHP 8.3.35 → 8.3.36',
            'Hạn xử lý: 24/10/2026.',
        )
        ->and($reply['thread']['threadKey'])->toBe(IncidentTeam::sole()->thread_key)
        ->and(sendPreNotices())->toBe(0);
});

it('tells a team told before threads about an update in a new thread with a full card, without replaying anything', function () {
    $this->preNotice->update(['affected_branches' => ['8.3']]);
    app(AssignNoticeToIncident::class)->assignUnassigned();
    $card = ChatNotification::factory()->for($this->commerce)->sent()->create(['kind' => ChatNotificationKind::PreNotice, 'dedupe_key' => "pre_notice:incident:{$this->preNotice->refresh()->incident_id}:{$this->commerce->id}:before-threads"]);
    IncidentTeamNotice::create(['team_id' => $this->commerce->id, 'advisory_id' => $this->preNotice->id, 'severity' => Severity::High, 'reason' => IncidentAlertReason::New, 'chat_notification_id' => $card->id]);

    expect(sendPreNotices())->toBe(0)
        ->and(IncidentTeam::sole())->told_confirmed_branches->toBe(['8.3'])->opening_notification_id->toBeNull();

    $this->preNotice->update(['severity' => Severity::Critical]);

    expect(sendPreNotices())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Cảnh báo chuẩn bị · Commerce', 'Mức độ tăng từ High lên Critical.')
        ->and(ChatNotification::latest('id')->first()->thread_role)->toBe(ChatThreadRole::Opener);
});

it('opens a new thread with a full card when the team moves to another space', function () {
    sendPreNotices();
    $oldKey = IncidentTeam::sole()->thread_key;

    $this->commerce->update(['google_chat_webhook_url' => chatWebhook('COMMERCE2')]);
    $this->travel(1)->hour();
    $this->preNotice->update(['severity' => Severity::Critical]);

    expect(sendPreNotices())->toBe(1);

    $card = chatMessagesTo('COMMERCE2')->sole();
    $thread = IncidentTeam::sole();
    expect($thread)->generation->toBe(2)->space->toBe('COMMERCE2')
        ->and($thread->thread_key)->not->toBe($oldKey)->toEndWith('-g2')
        ->and($card['thread']['threadKey'])->toBe($thread->thread_key)
        ->and(chatJson($card))->toContain('Cảnh báo chuẩn bị · Commerce', 'Mức độ tăng từ High lên Critical.')
        ->and($thread->thread_name)->toStartWith('spaces/COMMERCE2/threads/');
});

it('opens a new thread with a full card when only the webhook of the space changes, as thread keys belong to the webhook', function () {
    sendPreNotices();

    $this->commerce->update(['google_chat_webhook_url' => str_replace('demo-token', 'new-token', chatWebhook('COMMERCE'))]);
    $this->preNotice->update(['severity' => Severity::Critical]);
    sendPreNotices();

    expect(IncidentTeam::sole())->generation->toBe(2)->space->toBe('COMMERCE')
        ->and(ChatNotification::latest('id')->first())->thread_role->toBe(ChatThreadRole::Opener)->sent_as->toBe(ChatThreadRole::Opener);
});

it('opens the thread again with a full card when its opener failed', function () {
    Http::swap(new HttpFactory);
    Http::fake([chatWebhook('COMMERCE').'*' => Http::response('Not found', 404)]);
    fakeGoogleChat();
    sendPreNotices();

    expect(ChatNotification::where('kind', ChatNotificationKind::PreNotice)->sole()->failed_at)->not->toBeNull();

    $this->travel(1)->hour();
    $this->preNotice->update(['severity' => Severity::Critical]);
    sendPreNotices();

    expect(ChatNotification::where('kind', ChatNotificationKind::PreNotice)->latest('id')->first())
        ->thread_role->toBe(ChatThreadRole::Opener)
        ->and(IncidentTeam::sole()->opening_notification_id)->toBe(ChatNotification::where('kind', ChatNotificationKind::PreNotice)->latest('id')->value('id'));
});

it('lets the incident a merged one went into take over its thread, and keeps both rows', function () {
    $other = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Other PHP issue', 'severity' => Severity::Medium, 'aliases' => ['CVE-2026-2002'], 'published_at' => now()->subHour()]);
    sendPreNotices();
    $fromThread = IncidentTeam::sole();

    $into = app(MergeIncidents::class)->handle($this->preNotice->refresh()->incident, $other->refresh()->incident, User::factory()->create(['is_admin' => true]));

    $intoThread = IncidentTeam::where('incident_id', $into->id)->sole();
    expect(IncidentTeam::count())->toBe(2)
        ->and($fromThread->refresh()->merged_into_id)->toBe($intoThread->id)
        ->and($intoThread)->thread_key->toBe($fromThread->thread_key)->thread_name->toBe($fromThread->thread_name)->opening_notification_id->toBe($fromThread->opening_notification_id);

    $this->travel(1)->hour();
    $this->preNotice->update(['severity' => Severity::Critical]);

    expect(sendPreNotices())->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->last()['thread']['threadKey'])->toBe($fromThread->thread_key)
        ->and(ChatNotification::latest('id')->first()->sent_as)->toBe(ChatThreadRole::Reply);
});

it('starts a new thread for a pre-notice split off into its own incident', function () {
    $second = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP security advisory', 'severity' => Severity::High, 'aliases' => ['CVE-2026-1001'], 'published_at' => now()]);
    sendPreNotices();
    $oldKey = IncidentTeam::sole()->thread_key;

    $incident = app(SplitNotice::class)->handle($second->refresh(), User::factory()->create(['is_admin' => true]));
    $second->update(['severity' => Severity::Critical]);

    expect(sendPreNotices())->toBe(1);

    $card = chatMessagesTo('COMMERCE')->last();
    expect($card['thread']['threadKey'])->not->toBe($oldKey)->toBe(IncidentTeam::where('incident_id', $incident->id)->value('thread_key'))
        ->and(chatJson($card))->toContain('Cảnh báo chuẩn bị · Commerce');
});

it('sends updates to every team told and still affected, however long ago the incident began', function () {
    $booking = Team::factory()->create(['name' => 'Booking', 'google_chat_webhook_url' => chatWebhook('BOOKING')]);
    threadRuntime($booking, 'booking-api', '8.5.11');
    sendPreNotices();

    $this->travelTo('2027-03-01 00:00:00');
    $this->preNotice->update(['unaffected_branches' => ['8.5'], 'is_exploited' => true, 'exploited_basis' => ['method' => 'keyword', 'excerpt' => 'It is actively exploited.', 'content_hash' => 'x']]);
    $later = Team::factory()->create(['google_chat_webhook_url' => chatWebhook('LATER')]);
    threadRuntime($later, 'later-api', '8.3.35');

    expect(sendPreNotices())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('đang bị khai thác')
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(1)
        ->and(chatMessagesTo('LATER'))->toHaveCount(0);
});

it('still tells a team sent a card about the incident of an urgent change once the incident fell to Medium', function () {
    sendPreNotices();

    $this->travel(1)->hour();
    $this->preNotice->update(['severity' => Severity::Medium]);

    expect(sendPreNotices())->toBe(0);

    $this->preNotice->update(['is_exploited' => true, 'exploited_basis' => ['method' => 'keyword', 'excerpt' => 'It is actively exploited.', 'content_hash' => 'x']]);

    expect(sendPreNotices())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Hãng xác nhận lỗ hổng đang bị khai thác');
});

it('handles the most severe and newest incidents first and reads the runtimes of a technology once', function () {
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Older critical notice', 'severity' => Severity::Critical, 'aliases' => ['CVE-2026-3003'], 'published_at' => now()->subDays(2)]);
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Newer critical notice', 'severity' => Severity::Critical, 'aliases' => ['CVE-2026-4004'], 'published_at' => now()->subDay()]);
    app(AssignNoticeToIncident::class)->assignUnassigned();
    DB::enableQueryLog();

    expect(sendPreNotices())->toBe(3)
        ->and(chatMessagesTo('COMMERCE')->pluck('text')->all())->toBe(['[Critical] Newer critical notice', '[Critical] Older critical notice', '[High] Upcoming Critical PHP Security Release'])
        ->and(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'select * from "runtimes"')))->toHaveCount(1);
});

it('skips an incident nothing changed for since the last full run, and not one that changed', function () {
    sendPreNotices();
    $fingerprint = $this->preNotice->refresh()->incident->alert_fingerprint;

    expect($fingerprint)->not->toBeNull();

    DB::enableQueryLog();
    sendPreNotices();

    expect(collect(DB::getQueryLog())->contains(fn (array $query): bool => str_contains($query['query'], 'insert into "incident_team"')))->toBeFalse();

    $this->preNotice->update(['classified_by' => null, 'is_exploited' => true, 'exploited_basis' => ['method' => 'keyword', 'excerpt' => 'It is actively exploited.', 'content_hash' => 'x']]);

    expect(app(SendPreNoticeAlerts::class)->handle(holdUnclassified: true))->toBe(0)
        ->and($this->preNotice->incident->refresh()->alert_fingerprint)->toBe($fingerprint)
        ->and(sendPreNotices())->toBe(1)
        ->and($this->preNotice->incident->refresh()->alert_fingerprint)->not->toBe($fingerprint);
});

it('stores the fingerprint of what it decided on, so a fix confirmed while it ran is sent on the next run', function () {
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    $isReleased = false;
    ChatNotification::creating(function () use (&$isReleased): void {
        if (! $isReleased) {
            $isReleased = true;
            RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
        }
    });

    expect(sendPreNotices())->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->sole()['text'])->toBe('[High] Upcoming Critical PHP Security Release')
        ->and(sendPreNotices())->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->last()['text'])->toBe('[High] Đã có bản vá, nâng lên 8.3.36');
});

it('does not skip an incident once the alert settings change', function (array $settings) {
    $this->preNotice->update(['severity' => Severity::Medium]);
    Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Older PHP advisory', 'severity' => Severity::High, 'aliases' => ['CVE-2026-1001'], 'published_at' => now()->subDays(20)]);

    expect(sendPreNotices())->toBe(0)
        ->and($this->preNotice->refresh()->incident->alert_fingerprint)->not->toBeNull();

    config($settings);

    expect(sendPreNotices())->toBe(1);
})->with([
    'medium sent right away' => [['psm.alerts.immediate_severities' => ['critical', 'high', 'medium']]],
    'news fresh for longer' => [['psm.notices.relevant_days' => 30]],
]);

it('takes which pre-notices are fresh into the fingerprint, the only input that changes with time alone', function () {
    sendPreNotices();
    $fingerprint = $this->preNotice->refresh()->incident->alert_fingerprint;

    $this->travel(15)->days();
    sendPreNotices();

    expect($this->preNotice->incident->refresh()->alert_fingerprint)->not->toBe($fingerprint)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});

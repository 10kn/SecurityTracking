<?php

use App\Enums\ChatNotificationKind;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\SourceHeartbeat;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

beforeEach(function () {
    fakeGoogleChat();
});

it('alerts the admin space once per outage of a source', function () {
    SourceHeartbeat::factory()->stale()->create(['name' => 'Danh mục version PHP']);
    SourceHeartbeat::factory()->create(['name' => 'Danh mục version Ruby']);

    artisan('psm:check-sources')->assertSuccessful();
    artisan('psm:check-sources')->assertSuccessful();

    expect(chatMessagesTo('ADMIN'))->toHaveCount(1)
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Nguồn Danh mục version PHP lỗi quá ngưỡng', 'cURL error 28')->not->toContain('Ruby');
});

it('alerts again when the source fails again after recovering', function () {
    $heartbeat = SourceHeartbeat::factory()->stale()->create();
    artisan('psm:check-sources');

    $heartbeat->update(['last_success_at' => now()]);
    artisan('psm:check-sources');
    $this->travel($heartbeat->threshold_minutes + 1)->minutes();
    artisan('psm:check-sources');

    expect(chatMessagesTo('ADMIN'))->toHaveCount(2);
});

it('alerts about a source that never synced', function () {
    SourceHeartbeat::factory()->create(['last_success_at' => null, 'last_failure_at' => now()]);

    artisan('psm:check-sources')->assertSuccessful();

    expect(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Thành công gần nhất: chưa có');
});

it('tells the admin, without the queue, once about messages stuck in the queue', function () {
    Queue::fake();
    ChatNotification::factory()->create(['created_at' => now()->subMinutes(45)]);
    ChatNotification::factory()->create(['created_at' => now()->subMinutes(40)]);
    ChatNotification::factory()->create(['created_at' => now()->subMinutes(5)]);
    ChatNotification::factory()->sent()->create(['created_at' => now()->subHour()]);
    ChatNotification::factory()->failed()->create(['created_at' => now()->subHour()]);

    artisan('psm:check-sources')->assertSuccessful();
    artisan('psm:check-sources')->assertSuccessful();

    expect(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Tin Google Chat bị kẹt trong hàng đợi', '2 tin chưa gửi được sau 30 phút.')
        ->and(ChatNotification::where('kind', ChatNotificationKind::AdminAlert)->sole()->sent_at)->not->toBeNull();
    Queue::assertNothingPushed();
});

it('tells the admin once per outage about a pre-notice the classifier keeps failing on', function () {
    config(['psm.notices.classifier.api_key' => 'test-key']);
    $php = Technology::Php;
    $failing = Advisory::factory()->preNotice($php)->create(['title' => 'Upcoming Critical PHP Security Release', 'published_at' => now()->subDays(2), 'classified_by' => 'claude-haiku-5-5', 'classification_failed_at' => now()->subMinutes(61)]);
    Advisory::factory()->preNotice($php)->create(['published_at' => now()->subHours(2), 'classification_failed_at' => now()->subMinutes(10)]);
    Advisory::factory()->preNotice($php)->create(['published_at' => now()->subMonth(), 'classification_failed_at' => now()->subHours(2), 'is_backfill' => true]);

    artisan('psm:check-sources')->assertSuccessful();
    artisan('psm:check-sources')->assertSuccessful();

    expect(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('AI chưa phân loại được tin bảo mật', 'Upcoming Critical PHP Security Release');

    $failing->update(['classification_failed_at' => now()->subMinutes(90)]);
    artisan('psm:check-sources')->assertSuccessful();

    expect(chatMessagesTo('ADMIN'))->toHaveCount(2);
});

it('tries the alert about stuck messages again on the next check when Google Chat failed, even once the queue works again', function () {
    $adminDown = true;
    Http::swap(new Factory);
    Http::fake([chatWebhook('ADMIN') => function () use (&$adminDown) {
        return $adminDown ? Http::response('Service Unavailable', 503) : Http::response(['name' => 'spaces/ADMIN/messages/1']);
    }]);
    ChatNotification::factory()->create(['created_at' => now()->subMinutes(45)]);

    artisan('psm:check-sources')->assertSuccessful();
    ChatNotification::where('kind', ChatNotificationKind::Immediate)->update(['sent_at' => now()]);
    $adminDown = false;
    $this->travel(15)->minutes();
    artisan('psm:check-sources')->assertSuccessful();
    artisan('psm:check-sources')->assertSuccessful();

    expect(ChatNotification::where('kind', ChatNotificationKind::AdminAlert)->sole())
        ->sent_at->not->toBeNull()
        ->attempts->toBe(2)
        ->and(Http::recorded(fn ($request): bool => $request->url() === chatWebhook('ADMIN')))->toHaveCount(2);
});

it('does not check the classifier when none is configured', function () {
    Advisory::factory()->preNotice(Technology::Php)->create(['published_at' => now()->subHours(2), 'created_at' => now()->subHours(2)]);

    artisan('psm:check-sources')->assertSuccessful();

    expect(chatMessagesTo('ADMIN'))->toHaveCount(0);
});

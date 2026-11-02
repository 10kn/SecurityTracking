<?php

use App\Enums\FindingEventType;
use App\Enums\FindingRule;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\SourceHeartbeat;
use App\Models\Team;
use App\Services\Notices\NoticeClassification;
use App\Services\Notices\PhpNetNoticeFeed;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->travelTo('2026-10-23 10:00:00');
    fakeGoogleChat();
});

it('polls the news feed of every technology, once each', function () {
    fakeNoticeFeeds();

    artisan('psm:watch-notices')->assertSuccessful();

    expect(Advisory::groupBy('source')->selectRaw('source, count(*) as total')->pluck('total', 'source')->all())
        ->toEqual(['notice:nodejs' => 3, 'notice:php' => 3, 'notice:ruby' => 2])
        ->and(SourceHeartbeat::orderBy('source')->pluck('source')->all())->toBe(['notice:nodejs', 'notice:php', 'notice:ruby'])
        ->and(Http::recorded(fn (Request $request): bool => ! str_starts_with($request->url(), Team::GOOGLE_CHAT_WEBHOOK_PREFIX)))->toHaveCount(3);
});

it('keeps polling the other feeds when one fails, records the failure and alerts the admin once it is stale', function () {
    Sleep::fake();
    Http::fake([PhpNetNoticeFeed::URL => Http::response('Service Unavailable', 503)]);
    fakeNoticeFeeds();

    artisan('psm:watch-notices')->assertFailed();

    expect(Advisory::where('source', 'notice:nodejs')->count())->toBe(3)
        ->and(SourceHeartbeat::where('source', 'notice:php')->sole())
        ->last_failure_at->not->toBeNull()
        ->last_success_at->toBeNull();

    artisan('psm:check-sources')->assertSuccessful();

    expect(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Nguồn Tin bảo mật php.net lỗi quá ngưỡng', 'RequestException');
});

it('warns the team about a critical pre-notice without raising the severity of its runtime findings, until the vendor confirms the fix', function () {
    $this->travelTo('2026-10-20 22:00:00');
    $php = syncedTechnology(Technology::Php);
    fakeNoticeFeeds();
    $commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    runtimeAt($commerce, 'shop-api', $php, '8.3.35');
    artisan('psm:evaluate-runtimes')->assertSuccessful();

    $finding = Finding::where('rule', FindingRule::RuntimeOutdatedSecurity)->sole();
    expect($finding->severity)->toBe(Severity::High)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);

    artisan('psm:watch-notices')->assertSuccessful();

    expect($finding->refresh()->severity)->toBe(Severity::High)
        ->and(FindingEvent::where('type', FindingEventType::Escalated)->count())->toBe(0)
        ->and(chatMessagesTo('COMMERCE')->last()['text'])->toBe('[Critical] Upcoming Critical PHP Security Release');

    $this->travelTo('2026-10-21 00:40:00');
    artisan('psm:watch-notices')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE')->last()['text'])->toBe('[Critical] Đã có bản vá, nâng lên 8.3.36')
        ->and($finding->refresh())->severity->toBe(Severity::Critical)->notified_severity->toBe(Severity::Critical)
        ->and(FindingEvent::where('type', FindingEventType::Escalated)->count())->toBe(1);

    artisan('psm:watch-notices')->assertSuccessful();
    artisan('psm:evaluate-runtimes')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(3)
        ->and($finding->refresh()->severity)->toBe(Severity::Critical);
});

it('does not replay the history of a feed on its first poll', function () {
    fakeNoticeFeeds();
    $commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    runtimeAt($commerce, 'events', Technology::Nodejs, '22.20.0');
    runtimeAt($commerce, 'storefront', Technology::Ruby, '3.3.12');

    artisan('psm:watch-notices')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(0);
});

it('sends a critical pre-notice before the classifier reads anything', function () {
    $this->travelTo('2026-10-20 20:45:00');
    fakeNoticeFeeds();
    $commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    runtimeAt($commerce, 'shop-api', Technology::Php, '8.3.35');
    $sentWhenClassifying = [];
    classifyNoticesWith(function () use (&$sentWhenClassifying): NoticeClassification {
        $sentWhenClassifying[] = chatMessagesTo('COMMERCE')->count();

        return new NoticeClassification(isSecurityNews: true, severity: Severity::Critical);
    });

    artisan('psm:watch-notices')->assertSuccessful();

    expect($sentWhenClassifying)->not->toBeEmpty()
        ->and($sentWhenClassifying[0])->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->sole()['text'])->toBe('[Critical] Upcoming Critical PHP Security Release');
});

it('is scheduled every 15 minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'psm:watch-notices'));

    expect($event)
        ->expression->toBe('*/15 * * * *')
        ->withoutOverlapping->toBeTrue();
});

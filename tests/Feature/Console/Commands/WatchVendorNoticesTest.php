<?php

use App\Enums\FindingEventType;
use App\Enums\FindingRule;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\SourceHeartbeat;
use App\Models\Team;
use App\Models\Technology;
use App\Services\Notices\PhpNetNoticeFeed;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->travelTo('2026-10-23 10:00:00');
    fakeGoogleChat();
});

it('polls the news feed of every release source in use, once each', function () {
    fakeNoticeFeeds();
    Technology::factory()->php()->create();
    Technology::factory()->nodejs()->create();
    Technology::factory()->ruby()->create();
    Technology::factory()->create(['name' => 'Python', 'slug' => 'python', 'endoflife_product' => 'python']);

    artisan('psm:watch-notices')->assertSuccessful();

    expect(Advisory::groupBy('source')->selectRaw('source, count(*) as total')->pluck('total', 'source')->all())
        ->toEqual(['notice:nodejs' => 3, 'notice:php_net' => 3, 'notice:ruby_lang' => 2])
        ->and(SourceHeartbeat::orderBy('source')->pluck('source')->all())->toBe(['notice:nodejs', 'notice:php_net', 'notice:ruby_lang'])
        ->and(Http::recorded())->toHaveCount(3);
});

it('keeps polling the other feeds when one fails, records the failure and alerts the admin once it is stale', function () {
    Sleep::fake();
    Http::fake([PhpNetNoticeFeed::URL => Http::response('Service Unavailable', 503)]);
    fakeNoticeFeeds();
    Technology::factory()->php()->create();
    Technology::factory()->nodejs()->create();

    artisan('psm:watch-notices')->assertFailed();

    expect(Advisory::where('source', 'notice:nodejs')->count())->toBe(3)
        ->and(SourceHeartbeat::where('source', 'notice:php_net')->sole())
        ->last_failure_at->not->toBeNull()
        ->last_success_at->toBeNull();

    artisan('psm:check-sources')->assertSuccessful();

    expect(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Nguồn Tin bảo mật php.net lỗi quá ngưỡng', 'RequestException');
});

it('escalates a runtime missing a security fix to critical after a critical pre-notice and alerts the team again', function () {
    $php = syncedTechnology('php');
    fakeNoticeFeeds();
    $commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    runtimeAt($commerce, 'shop-api', $php, '8.3.35');
    artisan('psm:evaluate-runtimes')->assertSuccessful();

    $finding = Finding::where('rule', FindingRule::RuntimeOutdatedSecurity)->sole();
    expect($finding->severity)->toBe(Severity::High)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);

    artisan('psm:watch-notices')->assertSuccessful();

    $messages = chatMessagesTo('COMMERCE');
    expect($finding->refresh()->severity)->toBe(Severity::Critical)
        ->and(FindingEvent::where('finding_id', $finding->id)->where('type', FindingEventType::Escalated)->sole()->data)->toEqual(['from' => 'high', 'to' => 'critical'])
        ->and($messages)->toHaveCount(4)
        ->and($messages->pluck('text')->slice(1, 2)->sort()->values()->all())->toBe([
            '[Critical] Security advisory: Critical remote code execution vulnerabilities, update now',
            '[Critical] Upcoming Critical PHP Security Release',
        ])
        ->and($messages->last()['text'])->toBe('[Critical] PHP 8.3 thiếu bản vá bảo mật, cần nâng lên 8.3.36')
        ->and(chatJson($messages->last()))->toContain('Mở tin cảnh báo của hãng');

    artisan('psm:watch-notices')->assertSuccessful();
    artisan('psm:evaluate-runtimes')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(4);
});

it('stops escalating once the critical pre-notice is older than the configured days', function () {
    $php = syncedTechnology('php');
    fakeNoticeFeeds();
    runtimeAt(Team::factory()->create(), 'shop-api', $php, '8.3.35');
    artisan('psm:watch-notices')->assertSuccessful();
    artisan('psm:evaluate-runtimes')->assertSuccessful();
    expect(Finding::sole()->severity)->toBe(Severity::Critical);

    $this->travelTo('2026-11-05 00:00:00');
    artisan('psm:sync-runtimes')->assertSuccessful();

    expect(Finding::sole()->severity)->toBe(Severity::High);
});

it('is scheduled every 15 minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'psm:watch-notices'));

    expect($event)
        ->expression->toBe('*/15 * * * *')
        ->withoutOverlapping->toBeTrue();
});

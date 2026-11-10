<?php

use App\Actions\EvaluateRuntimes;
use App\Actions\ScanPackages;
use App\Actions\SendFindingAlerts;
use App\Actions\SendPreNoticeAlerts;
use App\Actions\SplitNotice;
use App\Actions\SyncComponents;
use App\Enums\FindingEventType;
use App\Enums\FindingRule;
use App\Enums\Severity;
use App\Enums\SeverityBasis;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\IncidentTeam;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\RuntimeRelease;
use App\Models\Service;
use App\Models\SourceHeartbeat;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

use function Pest\Laravel\artisan;

beforeEach(function () {
    fakeGoogleChat();
    $this->commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
});

/**
 * A PHP runtime of the team's service on a known branch.
 */
function kevRuntime(Team $team, string $service, string $version): Runtime
{
    $runtime = runtimeAt($team, $service, Technology::Php, $version);
    $runtime->update(['version' => $version, 'branch' => implode('.', array_slice(explode('.', $version), 0, 2))]);

    return $runtime;
}

/**
 * A pre-notice of php.net naming `$aliases` and telling to update to `$patchVersions`, which the catalog lists as
 * security releases.
 *
 * @param  list<string>  $aliases
 * @param  list<string>  $patchVersions
 */
function kevNotice(array $aliases, array $patchVersions = ['8.3.36', '8.4.27'], array $attributes = []): Advisory
{
    foreach ($patchVersions as $version) {
        RuntimeRelease::firstOrCreate(['technology' => Technology::Php, 'version' => $version], RuntimeRelease::factory()->security()->raw([
            'technology' => Technology::Php,
            'branch' => implode('.', array_slice(explode('.', $version), 0, 2)),
            'version' => $version,
        ]));
    }

    return Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'Security advisory: Critical remote code execution vulnerabilities, update now',
        'severity' => Severity::High,
        'aliases' => $aliases,
        'patch_versions' => $patchVersions,
        'url' => 'https://www.php.net/archive/2026.php#2026-10-21-1',
        'published_at' => '2026-10-21 00:35:26',
        ...$attributes,
    ]);
}

/**
 * The "thiếu bản vá" finding of the runtime, as the evaluation opens it.
 */
function evaluatedFinding(Runtime $runtime, string $latest = '8.3.36'): Finding
{
    $runtime->update(['status' => 'outdated_security', 'status_reason' => 'Đã có bản vá bảo mật, bản mới nhất của nhánh là :latest', 'status_context' => ['latest' => $latest]]);
    app(EvaluateRuntimes::class)->syncRuntimeFindings([$runtime->deployment_id]);

    return Finding::where('runtime_id', $runtime->id)->where('rule', FindingRule::RuntimeOutdatedSecurity)->sole();
}

it('tells the team in the incident thread once CISA KEV lists the CVEs after the "Đã có bản vá" card, raising its finding without a card of its own', function () {
    $this->travelTo('2026-10-20 22:00:00');
    $php = syncedTechnology(Technology::Php);
    fakeNoticeFeeds();
    classifyNoticeFixtures();
    fakeKevCatalog();
    runtimeAt($this->commerce, 'shop-api', $php, '8.3.35');
    artisan('psm:evaluate-runtimes')->assertSuccessful();
    artisan('psm:watch-notices')->assertSuccessful();
    $this->travelTo('2026-10-21 00:40:00');
    artisan('psm:watch-notices')->assertSuccessful();

    $finding = Finding::where('rule', FindingRule::RuntimeOutdatedSecurity)->sole();
    expect(chatMessagesTo('COMMERCE')->last()['text'])->toBe('🔴 [Critical] Đã có bản vá, nâng lên 8.3.36')
        ->and($finding->severity)->toBe(Severity::Critical)
        ->and($finding->sla_due_at->toDateTimeString())->toBe('2026-10-24 00:40:00');

    $this->travelTo('2026-10-27 10:00:00');
    artisan('psm:sync-kev')->assertSuccessful();

    $reply = chatMessagesTo('COMMERCE')->last();
    expect(chatMessagesTo('COMMERCE'))->toHaveCount(4)
        ->and(chatRequestsTo('COMMERCE')->last()->url())->toContain('messageReplyOption=REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD')
        ->and(chatJson($reply))->toContain(
            'CISA KEV ghi nhận CVE-2026-109618, CVE-2026-109619 đã bị khai thác trong thực tế (CISA thêm vào danh sách ngày 26/10/2026, hạn khắc phục CISA đặt: 29/10/2026).',
            'Lỗ hổng đã bị khai thác trong thực tế, không có nghĩa service của team đã bị tấn công',
            'shop-api',
        )->not->toContain('Hãng xác nhận lỗ hổng')
        ->and(IncidentTeam::whereNotNull('told_known_exploited')->sole()->told_known_exploited)->toBe(['CVE-2026-109618', 'CVE-2026-109619'])
        ->and(Advisory::where('title', 'like', 'Security advisory%')->sole())
        ->severity->toBe(Severity::Critical)
        ->severity_basis->toBe(SeverityBasis::Kev)
        ->severity_before_kev->toBe(Severity::Critical)
        ->and(Advisory::where('title', 'like', 'Security advisory%')->sole()->known_exploited)->toEqual([
            ['cve' => 'CVE-2026-109618', 'name' => 'PHP Memory Corruption Vulnerability', 'date_added' => '2026-10-26', 'due_date' => '2026-10-29', 'listed' => true],
            ['cve' => 'CVE-2026-109619', 'name' => 'PHP Stale Internal Request-State Vulnerability', 'date_added' => '2026-10-26', 'due_date' => '2026-10-29', 'listed' => true],
        ]);

    $finding->refresh();
    expect($finding)
        ->severity->toBe(Severity::Critical)
        ->notified_severity->toBe(Severity::Critical)
        ->sla_due_at->toDateTimeString()->toBe('2026-10-24 00:40:00')
        ->and($finding->context['known_exploited'])->toHaveCount(2)
        ->and($finding->context['related_patches'])->toEqual([[
            'incident_id' => $finding->context['related_patches'][0]['incident_id'],
            'notice_id' => Advisory::where('title', 'like', 'Security advisory%')->value('id'),
            'url' => 'https://www.php.net/archive/2026.php#2026-10-21-5',
            'fixed_version' => '8.3.36',
            'cves' => ['CVE-2026-109618', 'CVE-2026-109619'],
        ]])
        ->and(FindingEvent::where('finding_id', $finding->id)->where('type', FindingEventType::KnownExploited)->sole()->data)
        ->toBe(['cves' => ['CVE-2026-109618', 'CVE-2026-109619'], 'due_date' => '2026-10-29']);

    $this->travel(1)->hour();
    artisan('psm:sync-kev')->assertSuccessful();
    artisan('psm:watch-notices')->assertSuccessful();
    artisan('psm:evaluate-runtimes')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(4)
        ->and(FindingEvent::where('type', FindingEventType::KnownExploited)->count())->toBe(1)
        ->and(Advisory::where('title', 'like', 'Security advisory%')->sole()->known_exploited)->toHaveCount(2)
        ->and($finding->refresh()->context['known_exploited'])->toHaveCount(2);
});

it('keeps the KEV evidence of a pre-notice across polls of an unchanged feed, apart from what the vendor\'s words say', function () {
    $this->travelTo('2026-10-21 01:00:00');
    fakeNoticeFeeds();
    artisan('psm:watch-notices')->assertSuccessful();
    $notice = Advisory::where('title', 'like', 'Security advisory%')->sole();
    $isExploited = $notice->is_exploited;
    fakeKevCatalog();
    artisan('psm:sync-kev')->assertSuccessful();

    artisan('psm:watch-notices')->assertSuccessful();
    artisan('psm:watch-notices')->assertSuccessful();

    expect($notice->refresh())
        ->is_exploited->toBe($isExploited)
        ->severity->toBe(Severity::Critical)
        ->known_exploited_at->not->toBeNull()
        ->and(collect($notice->known_exploited)->pluck('cve')->all())->toBe(['CVE-2026-109618', 'CVE-2026-109619'])
        ->and(Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole()->known_exploited)->toBeNull();
});

it('raises a pre-notice the vendor did not rate to the KEV severity, saying CISA KEV gave it', function () {
    $this->travelTo('2026-10-27 10:00:00');
    $notice = kevNotice(['CVE-2026-109618'], attributes: ['severity' => Severity::Unknown, 'severity_basis' => null]);
    fakeKevCatalog();

    artisan('psm:sync-kev')->assertSuccessful();

    expect($notice->refresh())
        ->severity->toBe(Severity::Critical)
        ->severity_basis->toBe(SeverityBasis::Kev)
        ->and($notice->incident->severity)->toBe(Severity::Critical);
});

it('gives a pre-notice the KEV severity before the one it had, also a higher one, and keeps that one apart', function () {
    $this->travelTo('2026-10-27 10:00:00');
    config(['psm.kev.severity' => 'high']);
    $notice = kevNotice(['CVE-2026-109618'], attributes: ['severity' => Severity::Critical, 'severity_basis' => SeverityBasis::Classifier]);
    fakeKevCatalog();

    artisan('psm:sync-kev')->assertSuccessful();
    artisan('psm:sync-kev')->assertSuccessful();

    expect($notice->refresh())
        ->severity->toBe(Severity::High)
        ->severity_basis->toBe(SeverityBasis::Kev)
        ->severity_before_kev->toBe(Severity::Critical);
});

it('sends a first card about an old incident once CISA adds its CVE, to a team that runs no fix yet', function (string $dateAdded, int $cards) {
    $this->travelTo('2026-11-20 10:00:00');
    fakeKevCatalog(fn (array $entry): array => $entry['cveID'] === 'CVE-2026-109618' ? [...$entry, 'dateAdded' => $dateAdded, 'dueDate' => '2026-12-10'] : $entry);
    kevNotice(['CVE-2026-109618']);
    $booking = Team::factory()->create(['name' => 'Booking', 'google_chat_webhook_url' => chatWebhook('BOOKING')]);
    kevRuntime($this->commerce, 'shop-api', '8.4.27');
    kevRuntime($booking, 'booking-api', '8.3.30');

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);

    artisan('psm:sync-kev')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(0)
        ->and(chatMessagesTo('BOOKING'))->toHaveCount($cards);

    if ($cards > 0) {
        expect(chatMessagesTo('BOOKING')->sole()['text'])->toBe('🔴 [Critical] Đã có bản vá, nâng lên 8.3.36')
            ->and(chatJson(chatMessagesTo('BOOKING')->sole()))->toContain('CISA KEV ghi nhận CVE-2026-109618 đã bị khai thác trong thực tế (CISA thêm vào danh sách ngày 19/11/2026, hạn khắc phục CISA đặt: 10/12/2026).', 'Hạn xử lý: 23/11/2026.');
    }

    artisan('psm:sync-kev')->assertSuccessful();

    expect(chatMessagesTo('BOOKING'))->toHaveCount($cards);
})->with([
    'added yesterday' => ['2026-11-19', 1],
    'added before the configured days' => ['2026-10-26', 0],
]);

it('sends a fresh pre-notice only CISA KEV made urgent to the teams that run no fix yet', function () {
    $this->travelTo('2026-10-27 10:00:00');
    fakeKevCatalog();
    $notice = kevNotice(['CVE-2026-109618'], attributes: ['severity' => Severity::Medium, 'severity_basis' => SeverityBasis::Vendor, 'published_at' => '2026-10-27 09:00:00']);
    $booking = Team::factory()->create(['name' => 'Booking', 'google_chat_webhook_url' => chatWebhook('BOOKING')]);
    kevRuntime($this->commerce, 'shop-api', '8.3.36');
    kevRuntime($booking, 'booking-api', '8.3.35');

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);

    artisan('psm:sync-kev')->assertSuccessful();

    expect($notice->refresh())->severity_basis->toBe(SeverityBasis::Kev)->severity_before_kev->toBe(Severity::Medium)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(0)
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(1);
});

it('still sends a fresh pre-notice urgent on its own to every team running the technology when it names an old KEV entry', function () {
    $this->travelTo('2026-11-20 10:00:00');
    fakeKevCatalog();
    $notice = kevNotice(['CVE-2026-109618'], attributes: ['severity' => Severity::High, 'severity_basis' => SeverityBasis::Vendor, 'published_at' => '2026-11-20 09:00:00']);
    kevRuntime($this->commerce, 'shop-api', '8.3.36');

    artisan('psm:sync-kev')->assertSuccessful();

    expect($notice->refresh())->severity->toBe(Severity::Critical)->severity_before_kev->toBe(Severity::High)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});

it('sends a fresh pre-notice naming an old KEV entry once the vendor makes it urgent on its own, though KEV already made it Critical', function () {
    $this->travelTo('2026-11-20 10:00:00');
    fakeKevCatalog();
    $notice = kevNotice(['CVE-2026-109618'], attributes: ['severity' => Severity::Medium, 'severity_basis' => SeverityBasis::Vendor, 'published_at' => '2026-11-20 09:00:00']);
    kevRuntime($this->commerce, 'shop-api', '8.3.36');
    artisan('psm:sync-kev')->assertSuccessful();

    expect($notice->refresh()->severity)->toBe(Severity::Critical)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(0);

    $notice->update(['severity_before_kev' => Severity::High]);
    artisan('psm:sync-kev')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});

it('keeps alerting about a pre-notice CISA KEV brought back from history or the classifier dismissed', function () {
    $this->travelTo('2026-10-27 10:00:00');
    fakeKevCatalog();
    $notice = kevNotice(['CVE-2026-109618'], attributes: ['is_backfill' => true, 'is_dismissed' => true]);
    kevRuntime($this->commerce, 'shop-api', '8.3.35');

    artisan('psm:sync-kev')->assertSuccessful();

    expect($notice->refresh())->is_backfill->toBeFalse()->is_dismissed->toBeFalse()
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);

    $this->travelTo('2026-11-30 10:00:00');
    $notice->update(['affected_branches' => ['8.3']]);
    artisan('psm:sync-kev')->assertSuccessful();

    expect($notice->refresh()->is_backfill)->toBeFalse()
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Hãng xác nhận thêm nhánh bị ảnh hưởng: 8.3.');
});

it('tells a team each CVE id once, also once the pre-notice is split into an incident of its own', function () {
    $this->travelTo('2026-10-27 10:00:00');
    fakeKevCatalog();
    $announcement = kevNotice([], [], ['title' => 'Upcoming Critical PHP Security Release', 'url' => 'https://www.php.net/archive/2026.php#2026-10-20-1', 'published_at' => '2026-10-20 20:32:00']);
    $notice = kevNotice(['CVE-2026-109618'], [], ['links' => ['https://www.php.net/archive/2026.php#2026-10-20-1']]);
    kevRuntime($this->commerce, 'shop-api', '8.3.35');
    artisan('psm:sync-kev')->assertSuccessful();

    expect($notice->refresh()->incident_id)->toBe($announcement->refresh()->incident_id);

    $messages = chatMessagesTo('COMMERCE')->count();
    app(SplitNotice::class)->handle($notice, User::factory()->create(['is_admin' => true]));
    artisan('psm:sync-kev')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE')->slice($messages)->filter(fn (array $message): bool => str_contains(chatJson($message), 'CISA KEV ghi nhận')))->toHaveCount(0);
});

it('sends no first card about a CVE the team was told in another incident\'s thread', function () {
    $this->travelTo('2026-11-20 10:00:00');
    fakeKevCatalog(fn (array $entry): array => $entry['cveID'] === 'CVE-2026-109618' ? [...$entry, 'dateAdded' => '2026-11-19'] : $entry);
    kevNotice(['CVE-2026-109618'], [], ['title' => 'PHP security advisory', 'url' => 'https://www.php.net/archive/2026.php#a']);
    kevRuntime($this->commerce, 'shop-api', '8.3.35');
    artisan('psm:sync-kev')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1);

    $other = kevNotice(['GHSA-w8hw-w9fx-j79w'], [], ['title' => 'Another PHP advisory', 'url' => 'https://www.php.net/archive/2026.php#b', 'resolved_cve_ids' => ['GHSA-w8hw-w9fx-j79w' => ['cves' => ['CVE-2026-109618'], 'source' => 'github']]]);
    artisan('psm:sync-kev')->assertSuccessful();

    expect($other->refresh()->incident_id)->not->toBe(Advisory::where('title', 'PHP security advisory')->value('incident_id'))
        ->and($other->known_exploited)->not->toBeNull()
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);
});

it('looks up the GHSA ids of a pre-notice that no longer alerts once', function () {
    $this->travelTo('2026-11-20 10:00:00');
    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/advisories/GHSA-w8hw-w9fx-j79w' => Http::response(['ghsa_id' => 'GHSA-w8hw-w9fx-j79w', 'cve_id' => 'CVE-2026-109618'])]);
    fakeKevCatalog();
    $notice = kevNotice(['GHSA-w8hw-w9fx-j79w', 'GHSA-6wmf-4rv5-p3rp'], attributes: ['is_dismissed' => true]);

    artisan('psm:sync-kev')->assertSuccessful();
    artisan('psm:sync-kev')->assertSuccessful();

    expect(collect($notice->refresh()->known_exploited)->pluck('cve')->all())->toBe(['CVE-2026-109618'])
        ->and($notice->is_dismissed)->toBeFalse()
        ->and(Http::recorded(fn ($request) => str_contains($request->url(), 'GHSA-6wmf-4rv5-p3rp')))->toHaveCount(1);
});

it('stops matching a GHSA id the vendor no longer names', function () {
    $this->travelTo('2026-10-27 10:00:00');
    fakeKevCatalog();
    $notice = kevNotice(['GHSA-6wmf-4rv5-p3rp'], attributes: ['resolved_cve_ids' => ['GHSA-w8hw-w9fx-j79w' => ['cves' => ['CVE-2026-109618'], 'source' => 'github']]]);

    artisan('psm:sync-kev')->assertSuccessful();

    expect($notice->refresh()->known_exploited)->toBeNull();
});

it('does not tell a team already running the fix, and tells the others in their thread', function () {
    $this->travelTo('2026-10-21 01:00:00');
    fakeKevCatalog();
    kevNotice(['CVE-2026-109618']);
    $booking = Team::factory()->create(['name' => 'Booking', 'google_chat_webhook_url' => chatWebhook('BOOKING')]);
    $patched = kevRuntime($this->commerce, 'shop-api', '8.3.35');
    kevRuntime($booking, 'booking-api', '8.3.35');
    app(SendPreNoticeAlerts::class)->handle();
    $patched->update(['version' => '8.3.36', 'raw_version' => '8.3.36']);

    $this->travelTo('2026-10-27 10:00:00');
    artisan('psm:sync-kev')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1)
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(2)
        ->and(chatJson(chatMessagesTo('BOOKING')->last()))->toContain('CISA KEV ghi nhận CVE-2026-109618');
});

it('names only the CVE CISA lists when a pre-notice names several, and reaches the runtime finding through that CVE only', function () {
    $this->travelTo('2026-10-27 10:00:00');
    kevNotice(['CVE-2026-1001', 'CVE-2026-109618']);
    $finding = evaluatedFinding(kevRuntime($this->commerce, 'shop-api', '8.3.35'));
    fakeKevCatalog();

    artisan('psm:sync-kev')->assertSuccessful();

    $card = chatJson(chatMessagesTo('COMMERCE')->sole());
    expect($card)->toContain('CISA KEV ghi nhận CVE-2026-109618 đã bị khai thác')->not->toContain('CVE-2026-1001 đã bị khai thác')
        ->and(collect(Advisory::sole()->known_exploited)->pluck('cve')->all())->toBe(['CVE-2026-109618'])
        ->and(collect($finding->refresh()->context['known_exploited'])->pluck('cve')->all())->toBe(['CVE-2026-109618'])
        ->and($finding->context['related_patches'][0]['cves'])->toBe(['CVE-2026-1001', 'CVE-2026-109618'])
        ->and($finding->severity)->toBe(Severity::Critical);
});

it('leaves out of the runtime finding an incident the vendor said spares its branch', function () {
    $this->travelTo('2026-10-27 10:00:00');
    kevNotice(['CVE-2026-109618'], attributes: ['unaffected_branches' => ['8.3']]);
    $finding = evaluatedFinding(kevRuntime($this->commerce, 'shop-api', '8.3.35'));
    fakeKevCatalog();

    artisan('psm:sync-kev')->assertSuccessful();

    expect($finding->refresh()->context)->not->toHaveKeys(['related_patches', 'known_exploited'])
        ->and(FindingEvent::where('type', FindingEventType::KnownExploited)->count())->toBe(0);
});

it('matches a pre-notice naming only a GHSA id through the CVE of that id', function (Closure $source, int $githubRequests) {
    $this->travelTo('2026-10-27 10:00:00');
    Http::preventStrayRequests();
    $source();
    fakeKevCatalog();
    $notice = kevNotice(['GHSA-w8hw-w9fx-j79w']);

    artisan('psm:sync-kev')->assertSuccessful();

    expect($notice->refresh()->resolved_cve_ids['GHSA-w8hw-w9fx-j79w']['cves'])->toBe(['CVE-2026-109618'])
        ->and(collect($notice->known_exploited)->pluck('cve')->all())->toBe(['CVE-2026-109618'])
        ->and($notice->severity)->toBe(Severity::Critical)
        ->and(Http::recorded(fn ($request) => str_starts_with($request->url(), 'https://api.github.com/advisories/')))->toHaveCount($githubRequests)
        ->and(SourceHeartbeat::where('source', 'kev:ghsa')->sole()->last_success_at)->not->toBeNull();
})->with([
    'from the GitHub advisory database' => [fn () => Http::fake(['https://api.github.com/advisories/GHSA-w8hw-w9fx-j79w' => Http::response(['ghsa_id' => 'GHSA-w8hw-w9fx-j79w', 'cve_id' => 'CVE-2026-109618', 'identifiers' => [['type' => 'GHSA', 'value' => 'GHSA-w8hw-w9fx-j79w'], ['type' => 'CVE', 'value' => 'CVE-2026-109618']]])]), 1],
    'from a package advisory already stored' => [fn () => Advisory::factory()->create(['source' => 'packagist', 'external_id' => 'PKSA-abcd-efgh-ijkl', 'aliases' => ['GHSA-w8hw-w9fx-j79w', 'CVE-2026-109618']]), 0],
]);

it('looks a GHSA id GitHub does not know yet up again on the next run', function () {
    $this->travelTo('2026-10-27 10:00:00');
    Http::preventStrayRequests();
    $known = false;
    Http::fake(['https://api.github.com/advisories/*' => function () use (&$known) {
        return $known ? Http::response(['ghsa_id' => 'GHSA-w8hw-w9fx-j79w', 'cve_id' => 'CVE-2026-109618']) : Http::response(['message' => 'Not Found'], 404);
    }]);
    fakeKevCatalog();
    $notice = kevNotice(['GHSA-w8hw-w9fx-j79w']);

    artisan('psm:sync-kev')->assertSuccessful();

    expect($notice->refresh()->resolved_cve_ids)->toBe(['GHSA-w8hw-w9fx-j79w' => ['cves' => [], 'source' => 'github']])
        ->and($notice->known_exploited)->toBeNull();

    $known = true;
    $this->travel(1)->hour();
    artisan('psm:sync-kev')->assertSuccessful();

    expect(collect($notice->refresh()->known_exploited)->pluck('cve')->all())->toBe(['CVE-2026-109618']);
});

it('sends a package finding again once CISA lists its CVE, even one already sent at Critical or only installed for development', function () {
    $this->travelTo('2026-10-27 10:00:00');
    fakePackagist();
    fakeKevCatalog(fn (array $entry): ?array => null);
    $deployment = Deployment::factory()->for(Service::factory()->for(Project::factory()->for($this->commerce))->state(['name' => 'shop-api']))->create();
    $deployment->lockfiles()->create(Lockfile::factory()->withContent('composer.lock', lockfileFixture('composer.lock'))->raw(['deployment_id' => $deployment->id]));
    app(SyncComponents::class)->handle($deployment);
    app(ScanPackages::class)->handle([$deployment->id]);
    app(SendFindingAlerts::class)->handle();
    $jwt = Finding::where('title', 'GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt')->sole();
    $phpunit = Finding::where('title', 'GHSA-vvj3-c3rp-c85p trong phpunit/phpunit')->sole();
    $jwt->update(['acknowledged_at' => now(), 'acknowledged_by' => User::factory()->create()->id]);

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1)
        ->and($phpunit->notified_severity)->toBeNull();

    $this->travel(1)->hour();
    fakeKevCatalog(fn (array $entry): array => match ($entry['cveID']) {
        'CVE-2020-11023' => [...$entry, 'cveID' => 'CVE-2021-46743', 'dateAdded' => '2026-10-27', 'dueDate' => '2026-11-17'],
        'CVE-2021-3129' => [...$entry, 'cveID' => 'CVE-2026-24765', 'dateAdded' => '2026-10-27', 'dueDate' => '2026-11-03'],
        default => $entry,
    });
    artisan('psm:sync-kev')->assertSuccessful();

    $cards = chatMessagesTo('COMMERCE')->slice(1)->values();
    expect($cards->pluck('text')->sort()->values()->all())->toBe([
        '🔴 [Critical] GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt',
        '🔴 [Critical] GHSA-vvj3-c3rp-c85p trong phpunit/phpunit',
    ])
        ->and(chatJson($cards->firstWhere('text', '🔴 [Critical] GHSA-vvj3-c3rp-c85p trong phpunit/phpunit')))->toContain(
            'CISA KEV ghi nhận CVE-2026-24765 đã bị khai thác trong thực tế (CISA thêm vào danh sách ngày 27/10/2026, hạn khắc phục CISA đặt: 03/11/2026).',
            'Mức theo nguồn: High.',
            'Mở CISA KEV',
        )
        ->and($jwt->refresh())
        ->acknowledged_at->toBeNull()
        ->notified_severity->toBe(Severity::Critical)
        ->sla_due_at->toDateTimeString()->toBe('2026-10-30 10:00:00')
        ->and($jwt->context)->toMatchArray(['source_severity' => 'critical'])
        ->and($phpunit->refresh())
        ->severity->toBe(Severity::Critical)
        ->sla_due_at->toDateTimeString()->toBe('2026-10-30 11:00:00')
        ->and($phpunit->advisory->severity)->toBe(Severity::High);

    $this->travel(1)->hour();
    artisan('psm:sync-kev')->assertSuccessful();
    app(ScanPackages::class)->handle([$deployment->id]);
    app(SendFindingAlerts::class)->handle();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(3)
        ->and($phpunit->refresh()->severity)->toBe(Severity::Critical)
        ->and(FindingEvent::where('type', FindingEventType::KnownExploited)->count())->toBe(2);
});

it('names every CVE id CISA KEV lists of the findings one card groups, with the fix that addresses each', function () {
    $this->travelTo('2026-11-20 10:00:00');
    fakeKevCatalog();
    kevNotice(['CVE-2026-109618'], ['8.3.33'], ['url' => 'https://www.php.net/archive/2026.php#2026-09-01-1']);
    kevNotice(['CVE-2026-109619'], ['8.3.36']);
    evaluatedFinding(kevRuntime($this->commerce, 'shop-api', '8.3.30'));
    evaluatedFinding(kevRuntime($this->commerce, 'shop-web', '8.3.35'));

    artisan('psm:sync-kev')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE'))->toHaveCount(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('CISA KEV ghi nhận CVE-2026-109618, CVE-2026-109619', 'Bản vá 8.3.33 của nhánh 8.3 xử lý CVE-2026-109618.', 'Bản vá 8.3.36 của nhánh 8.3 xử lý CVE-2026-109619.');
});

it('tells the admin once when CISA removes a CVE it had matched, keeping what it raised', function () {
    $this->travelTo('2026-10-27 10:00:00');
    $notice = kevNotice(['CVE-2026-109618']);
    fakeKevCatalog();
    artisan('psm:sync-kev')->assertSuccessful();

    $this->travel(1)->hour();
    fakeKevCatalog(fn (array $entry): ?array => $entry['cveID'] === 'CVE-2026-109618' ? null : $entry);
    artisan('psm:sync-kev')->assertSuccessful();
    artisan('psm:sync-kev')->assertSuccessful();

    expect(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('CISA đã gỡ CVE-2026-109618 khỏi danh sách KEV')
        ->and($notice->refresh()->severity)->toBe(Severity::Critical)
        ->and($notice->known_exploited[0]['listed'])->toBeFalse();
});

it('alerts the admin once the catalog cannot be read past its threshold, and still sends what the catalog read last reaches', function () {
    Sleep::fake();
    $this->travelTo('2026-10-27 10:00:00');
    fakeKevCatalog();
    artisan('psm:sync-kev')->assertSuccessful();

    fakeKevResponse('Service Unavailable', 503);
    kevNotice(['CVE-2026-109618']);
    kevRuntime($this->commerce, 'shop-api', '8.3.35');
    $this->travel(1)->hour();

    artisan('psm:sync-kev')->assertFailed();

    expect(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('CISA KEV ghi nhận CVE-2026-109618');

    $this->travel(6)->hours();
    artisan('psm:sync-kev')->assertFailed();
    artisan('psm:check-sources')->assertSuccessful();
    artisan('psm:check-sources')->assertSuccessful();

    expect(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Nguồn CISA KEV lỗi quá ngưỡng', 'RequestException');
});

it('runs every hour', function () {
    $event = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => str_contains($event->command, 'psm:sync-kev'));

    expect($event->expression)->toBe('20 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

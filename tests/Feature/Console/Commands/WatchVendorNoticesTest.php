<?php

use App\Enums\FindingEventType;
use App\Enums\FindingRule;
use App\Enums\IncidentScope;
use App\Enums\Severity;
use App\Enums\SeverityBasis;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\IncidentTeamNotice;
use App\Models\SourceHeartbeat;
use App\Models\Team;
use App\Services\Notices\ApacheHttpdNoticeFeed;
use App\Services\Notices\CveCnaRecords;
use App\Services\Notices\NginxNoticeFeed;
use App\Services\Notices\NodejsNoticeFeed;
use App\Services\Notices\NoticeClassification;
use App\Services\Notices\NoticeEntry;
use App\Services\Notices\PhpNetNoticeFeed;
use App\Services\Notices\RubyLangNoticeFeed;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->travelTo('2026-10-23 10:00:00');
    fakeGoogleChat();
});

it('polls the news feed of every technology, once each', function () {
    fakeNoticeFeeds();

    artisan('psm:watch-notices')->assertSuccessful();

    expect(Advisory::groupBy('source')->selectRaw('source, count(*) as total')->pluck('total', 'source')->all())
        ->toEqual(['notice:nodejs' => 2, 'notice:php' => 6, 'notice:ruby' => 4])
        ->and(SourceHeartbeat::orderBy('source')->pluck('source')->all())->toBe(['notice:apache', 'notice:nginx', 'notice:nodejs', 'notice:php', 'notice:ruby'])
        ->and(Http::recorded(fn (Request $request): bool => ! str_starts_with($request->url(), Team::GOOGLE_CHAT_WEBHOOK_PREFIX))->map(fn (array $pair): string => $pair[0]->url())->sort()->values()->all())->toBe([
            ApacheHttpdNoticeFeed::URL,
            NginxNoticeFeed::CHANGELOG,
            'https://nodejs.org/en/blog/vulnerability/july-2026-security-releases',
            'https://nodejs.org/en/blog/vulnerability/october-2026-security-releases',
            NodejsNoticeFeed::URL,
            NginxNoticeFeed::URL,
            PhpNetNoticeFeed::URL.'?psm_poll=1991944',
            RubyLangNoticeFeed::URL,
        ]);
});

it('records web server CVEs and confirms vendor patches without the AI classifier', function () {
    $this->travelTo('2026-11-19 10:00:00');
    config(['psm.notices.collect_days' => 60]);
    Http::fake([
        ApacheHttpdNoticeFeed::URL => Http::response([[
            'cveMetadata' => ['cveId' => 'CVE-2026-119618', 'state' => 'PUBLISHED'],
            'containers' => ['cna' => [
                'title' => 'HTTP/2 module memory corruption',
                'descriptions' => [['lang' => 'en', 'value' => 'Affected releases can allow remote code execution.']],
                'metrics' => [['other' => ['content' => ['text' => 'critical']]]],
                'timeline' => [['time' => '2026-11-11T12:00:00Z', 'value' => '2.4.71 released']],
            ]],
        ]]),
        NginxNoticeFeed::URL => Http::response('<article><section><security><item name="HTTP/2 memory corruption" severity="major" cve="2026-123007" vulnerable="1.9.5-1.31.8" good="1.31.9+, 1.30.7+"/></security></section></article>'),
        NginxNoticeFeed::CHANGELOG => Http::response("Changes with nginx 1.31.9                                        18 Nov 2026\n"),
        'https://archive.apache.org/dist/httpd/' => Http::response('<a href="httpd-2.4.71.tar.gz">2.4.71</a>'),
        'https://nginx.org/download/' => Http::response('<a href="nginx-1.31.9.tar.gz">1.31.9</a><a href="nginx-1.30.7.tar.gz">1.30.7</a>'),
    ]);
    fakeCatalogSources();
    fakeNoticeFeeds();

    artisan('psm:sync-runtimes', ['--technology' => ['apache', 'nginx']])->assertSuccessful();
    artisan('psm:watch-notices')->assertSuccessful();
    artisan('psm:watch-notices')->assertSuccessful();

    $apache = Advisory::where('source', 'notice:apache')->sole();
    expect($apache)
        ->severity->toBe(Severity::Critical)
        ->severity_basis->toBe(SeverityBasis::VendorAdvisory)
        ->is_backfill->toBeFalse()
        ->is_dismissed->toBeFalse()
        ->classified_by->toBeNull()
        ->aliases->toBe(['CVE-2026-119618'])
        ->and($apache->incident->confirmedPatches()['2.4']['version'])->toBe('2.4.71');

    $nginx = Advisory::where('source', 'notice:nginx')->sole();
    expect($nginx)
        ->severity->toBe(Severity::High)
        ->is_dismissed->toBeFalse()
        ->and($nginx->vendor_advisory['label'])->toBe('major')
        ->and(collect($nginx->incident->confirmedPatches())->pluck('version', null)->sort()->values()->all())
        ->toBe(['1.30.7', '1.31.9']);
});

it('uses the authoritative CVSS after a delayed lookup and alerts an affected team once while evidence is fresh', function () {
    $this->travelTo('2026-11-19 04:15:00');
    config(['inertia.ssr.enabled' => false]);
    $cve = 'CVE-2026-123007';
    $lookup = CveCnaRecords::URL."/2026/123xxx/{$cve}.json";
    $record = [
        'cveMetadata' => ['cveId' => $cve, 'state' => 'PUBLISHED', 'assignerShortName' => 'f5', 'datePublished' => '2026-11-18T15:23:29.008Z'],
        'containers' => [
            'cna' => ['datePublic' => '2026-11-18T15:00:00Z', 'metrics' => [
                ['cvssV3_1' => ['baseScore' => 8.1]],
                ['cvssV4_0' => ['baseScore' => 9.2]],
            ]],
            'adp' => [['metrics' => [['cvssV4_0' => ['baseScore' => 10.0]]]]],
        ],
    ];
    Http::fake([
        $lookup => Http::sequence()->pushStatus(503)->push($record),
        NginxNoticeFeed::URL => Http::response('<article><section><security><item name="HTTP/2 memory corruption" severity="major" cve="2026-123007" vulnerable="1.9.5-1.31.8" good="1.31.9+, 1.30.7+"/></security></section></article>'),
        NginxNoticeFeed::CHANGELOG => Http::response("Changes with nginx 1.31.9                                        18 Nov 2026\n"),
    ]);
    fakeNoticeFeeds();
    $behind = Team::factory()->create(['google_chat_webhook_url' => chatWebhook('NGINX_BEHIND')]);
    $patched = Team::factory()->create(['google_chat_webhook_url' => chatWebhook('NGINX_PATCHED')]);
    $stableBehind = runtimeAt($behind, 'nginx-stable-behind', Technology::Nginx, '1.30.6');
    $stableFixed = runtimeAt($patched, 'nginx-stable-fixed', Technology::Nginx, '1.30.7');
    $mainlineBehind = runtimeAt($behind, 'nginx-mainline-behind', Technology::Nginx, '1.31.8');

    artisan('psm:watch-notices')->assertSuccessful();

    $notice = Advisory::where('source', 'notice:nginx')->sole();
    expect($notice)->severity->toBe(Severity::High)->is_backfill->toBeTrue()->cna_record->toBeNull();
    expect(chatMessagesTo('NGINX_BEHIND'))->toBeEmpty();
    actingAsAdmin();
    get("/admin/incidents/{$notice->incident_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('incident.first_published', '18/11/2026'));

    $this->travel(15)->minutes();
    artisan('psm:watch-notices')->assertSuccessful();
    artisan('psm:watch-notices')->assertSuccessful();

    expect($notice->refresh())->severity->toBe(Severity::Critical)->severity_basis->toBe(SeverityBasis::CnaCvss)->is_backfill->toBeFalse();
    expect($notice->cna_record['scores'])->toEqual(['3.1' => 8.1, '4.0' => 9.2]);
    expect($notice->summary)->toContain($cve, 'NGINX advisory severity: major');
    expect($notice->incident->scopeFor($stableBehind))->toBe(IncidentScope::Confirmed)
        ->and($notice->incident->scopeFor($stableFixed))->toBe(IncidentScope::NotAffected)
        ->and($notice->incident->scopeFor($mainlineBehind))->toBe(IncidentScope::Confirmed);
    expect(chatMessagesTo('NGINX_BEHIND'))->toHaveCount(1)
        ->and(chatMessagesTo('NGINX_PATCHED'))->toBeEmpty();
    get("/admin/incidents/{$notice->incident_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('incident.ratings.vendor', 'NGINX Major')
        ->where('incident.ratings.provider', 'f5')
        ->where('incident.first_published', '18/11/2026 22:00 GMT+7')
        ->where('notices.0.vulnerable_versions', '1.9.5-1.31.8'));
});

it('rules out stable versions for a mainline-only CVE and preserves the explicit none label on old news', function () {
    $this->travelTo('2026-11-19 04:15:00');
    config(['inertia.ssr.enabled' => false]);
    Http::fake([
        NginxNoticeFeed::URL => Http::response(<<<'XML'
            <article><section><security>
                <item name="PROXY protocol" severity="minor" cve="2026-120993" vulnerable="1.31.4-1.31.8" good="1.31.9+"/>
                <item name="Error log" severity="none" cve="2009-4487" vulnerable="all" good="none"/>
            </security></section></article>
            XML),
        NginxNoticeFeed::CHANGELOG => Http::response("Changes with nginx 1.31.9                                        18 Nov 2026\n"),
        CveCnaRecords::URL.'/2009/4xxx/CVE-2009-4487.json' => Http::response([
            'cveMetadata' => ['cveId' => 'CVE-2009-4487', 'state' => 'PUBLISHED', 'datePublished' => '2010-01-10T00:00:00Z'],
            'containers' => ['cna' => ['providerMetadata' => ['shortName' => 'mitre']]],
        ]),
    ]);
    fakeNoticeFeeds();
    $team = Team::factory()->create(['google_chat_webhook_url' => chatWebhook('NGINX_STABLE')]);
    $stable = runtimeAt($team, 'nginx-stable', Technology::Nginx, '1.30.6');
    $before = runtimeAt($team, 'nginx-before-range', Technology::Nginx, '1.31.3');
    $first = runtimeAt($team, 'nginx-first-affected', Technology::Nginx, '1.31.4');

    artisan('psm:watch-notices')->assertSuccessful();

    $mainlineOnly = Advisory::where('external_id', 'CVE-2026-120993')->sole()->incident;
    expect($mainlineOnly->scopeFor($stable))->toBe(IncidentScope::NotAffected)
        ->and($mainlineOnly->scopeFor($before))->toBe(IncidentScope::NotAffected)
        ->and($mainlineOnly->scopeFor($first))->toBe(IncidentScope::Confirmed);
    $old = Advisory::where('external_id', 'CVE-2009-4487')->sole();
    expect($old->vendor_advisory['label'])->toBe('none')->and($old->is_backfill)->toBeTrue();
    expect(chatMessagesTo('NGINX_STABLE'))->toBeEmpty();
    actingAsAdmin();
    get("/admin/incidents/{$old->incident_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('incident.severity.label', 'None')
        ->where('incident.ratings.vendor', 'NGINX None'));
});

it('replays the seventeen NGINX advisories from the November audit without notifying for historical or patched releases', function () {
    $this->travelTo('2026-11-19 04:15:00');
    $advisories = [
        ['2026-123634', 'medium', '0.6.18-1.31.8', '1.31.9+, 1.30.7+', 9.2],
        ['2026-123007', 'major', '1.9.5-1.31.8', '1.31.9+, 1.30.7+', 9.2],
        ['2026-121810', 'medium', '1.25.0-1.31.8', '1.31.9+, 1.30.7+', 9.2],
        ['2026-123633', 'medium', '1.19.7-1.31.8', '1.31.9+, 1.30.7+', 9.2],
        ['2026-118899', 'major', '1.13.0-1.31.8', '1.31.9+, 1.30.7+', 8.7],
        ['2026-113425', 'major', '0.5.33-1.31.8', '1.31.9+, 1.30.7+', 9.2],
        ['2026-120993', 'minor', '1.31.4-1.31.8', '1.31.9+', 6.9],
        ['2026-125283', 'minor', '0.9.6-1.31.8', '1.31.9+, 1.30.7+', 6.9],
        ['2026-120967', 'medium', '1.25.5-1.31.8', '1.31.9+, 1.30.7+', 6.3],
        ['2026-123640', 'minor', '1.15.2-1.31.8', '1.31.9+, 1.30.7+', 6.9],
        ['2026-15176', 'medium', '0.1.29-1.31.6', '1.31.7+, 1.30.6+', 9.2],
        ['2026-93479', 'medium', '1.31.2-1.31.6, 1.30.0-1.30.5', '1.31.7+, 1.30.6+', 9.2],
        ['2026-95101', 'medium', '1.25.0-1.31.6', '1.31.7+, 1.30.6+', 6.9],
        ['2026-97629', 'medium', '1.23.2-1.31.6', '1.31.7+, 1.30.6+', 8.3],
        ['2026-97205', 'medium', '0.8.11-1.31.6', '1.31.7+, 1.30.6+', 9.2],
        ['2026-90439', 'medium', '1.29.2-1.31.5', '1.31.6+, 1.30.5+', 6.9],
        ['2009-4487', 'none', 'all', 'none', null],
    ];
    $items = [];
    $responses = [NginxNoticeFeed::CHANGELOG => Http::response(<<<'CHANGELOG'
        Changes with nginx 1.31.9                                        18 Nov 2026
        Changes with nginx 1.31.7                                        14 Oct 2026
        Changes with nginx 1.31.6                                        15 Sep 2026
        CHANGELOG)];

    foreach ($advisories as [$id, $label, $vulnerable, $safe, $score]) {
        $items[] = "<item name=\"CVE-{$id}\" cve=\"{$id}\" severity=\"{$label}\" vulnerable=\"{$vulnerable}\" good=\"{$safe}\"/>";
        [$year, $number] = explode('-', $id);
        $responses[CveCnaRecords::URL.'/'.$year.'/'.substr($number, 0, -3)."xxx/CVE-{$id}.json"] = Http::response([
            'cveMetadata' => ['cveId' => 'CVE-'.$id, 'state' => 'PUBLISHED'],
            'containers' => ['cna' => ['providerMetadata' => ['shortName' => $score === null ? 'mitre' : 'f5'],
                'metrics' => $score === null ? [] : [['cvssV4_0' => ['baseScore' => $score]]]]],
        ]);
    }

    Http::fake([NginxNoticeFeed::URL => Http::response('<article><section><security>'.implode('', $items).'</security></section></article>'), ...$responses]);
    fakeNoticeFeeds();
    $behind = Team::factory()->create(['google_chat_webhook_url' => chatWebhook('NGINX_AUDIT_BEHIND')]);
    $patched = Team::factory()->create(['google_chat_webhook_url' => chatWebhook('NGINX_AUDIT_PATCHED')]);
    runtimeAt($behind, 'audit-nginx-behind', Technology::Nginx, '1.30.6');
    runtimeAt($patched, 'audit-nginx-patched', Technology::Nginx, '1.30.7');

    artisan('psm:watch-notices')->assertSuccessful();
    artisan('psm:watch-notices')->assertSuccessful();

    expect(Advisory::where('source', 'notice:nginx')->count())->toBe(17)
        ->and(Advisory::where('source', 'notice:nginx')->where('severity', Severity::Critical)->count())->toBe(8)
        ->and(chatMessagesTo('NGINX_AUDIT_BEHIND'))->toHaveCount(5)
        ->and(chatMessagesTo('NGINX_AUDIT_PATCHED'))->toBeEmpty();
    expect(Advisory::whereIn('id', IncidentTeamNotice::where('team_id', $behind->id)->select('advisory_id'))->orderBy('external_id')->pluck('external_id')->all())
        ->toBe(['CVE-2026-113425', 'CVE-2026-121810', 'CVE-2026-123007', 'CVE-2026-123633', 'CVE-2026-123634']);
});

it('keeps polling the other feeds when one fails, records the failure and alerts the admin once it is stale', function () {
    Sleep::fake();
    Http::fake([PhpNetNoticeFeed::URL.'?psm_poll=*' => Http::response('Service Unavailable', 503)]);
    fakeNoticeFeeds();

    artisan('psm:watch-notices')->assertFailed();

    expect(Advisory::where('source', 'notice:nodejs')->count())->toBe(2)
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
    classifyNoticeFixtures();
    $commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    runtimeAt($commerce, 'shop-api', $php, '8.3.35');
    artisan('psm:evaluate-runtimes')->assertSuccessful();

    $finding = Finding::where('rule', FindingRule::RuntimeOutdatedSecurity)->sole();
    expect($finding->severity)->toBe(Severity::High)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1);

    artisan('psm:watch-notices')->assertSuccessful();

    expect($finding->refresh()->severity)->toBe(Severity::High)
        ->and(FindingEvent::where('type', FindingEventType::Escalated)->count())->toBe(0)
        ->and(chatMessagesTo('COMMERCE')->last()['text'])->toBe('🔴 [Critical] Upcoming Critical PHP Security Release');

    $this->travelTo('2026-10-21 00:40:00');
    artisan('psm:watch-notices')->assertSuccessful();

    expect(chatMessagesTo('COMMERCE')->last()['text'])->toBe('🔴 [Critical] Đã có bản vá, nâng lên 8.3.36')
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
    classifyNoticesWith(function (NoticeEntry $entry) use (&$sentWhenClassifying): NoticeClassification {
        $sentWhenClassifying[] = chatMessagesTo('COMMERCE')->count();
        $isSecurityNews = str_contains($entry->title, 'Security');

        return new NoticeClassification(isSecurityNews: $isSecurityNews, severity: $isSecurityNews ? Severity::Critical : Severity::Unknown);
    });

    artisan('psm:watch-notices')->assertSuccessful();

    expect($sentWhenClassifying)->not->toBeEmpty()
        ->and($sentWhenClassifying[0])->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->sole()['text'])->toBe('🔴 [Critical] Upcoming Critical PHP Security Release');
});

it('is scheduled every 15 minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'psm:watch-notices'));

    expect($event)
        ->expression->toBe('*/15 * * * *')
        ->withoutOverlapping->toBeTrue();
});

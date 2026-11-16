<?php

use App\Actions\SyncOsAdvisoryFeed;
use App\Enums\AdvisoryKind;
use App\Enums\LinuxDistribution;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\KnownExploitedVulnerability;
use App\Models\SourceHeartbeat;
use App\Services\OsAdvisories\DebianDsaFeed;
use App\Services\OsAdvisories\UbuntuUsnFeed;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-11-09 10:00:00');
});

it('stores the Amazon Linux 2023 advisories of the collection window with Amazon\'s rating, CVE ids and packages', function () {
    fakeOsAdvisoryFeeds();

    $advisories = app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::AmazonLinux2023);

    expect($advisories)->toHaveCount(3)
        ->and(Advisory::orderBy('external_id')->pluck('external_id')->all())->toBe(['ALAS2023-2026-3338', 'ALAS2023-2026-3339', 'ALAS2023-2026-3340'])
        ->and(Advisory::firstWhere('external_id', 'ALAS2023-2026-3338'))
        ->kind->toBe(AdvisoryKind::OsAdvisory)
        ->source->toBe('os:amazon-linux-2023')
        ->operating_system->toBe(LinuxDistribution::AmazonLinux2023)
        ->title->toBe('ALAS2023-2026-3338: emacs')
        ->severity->toBe(Severity::High)
        ->aliases->toBe(['CVE-2026-108976'])
        ->os_releases->toBe(['2023'])
        ->fixed_packages->toEqual([['release' => '2023', 'name' => 'emacs', 'version' => null]])
        ->url->toBe('https://alas.aws.amazon.com/AL2023/ALAS2023-2026-3338.html')
        ->published_at->toIso8601String()->toBe('2026-10-30T21:00:00+00:00')
        ->technology->toBeNull()
        ->and(Advisory::firstWhere('external_id', 'ALAS2023-2026-3339')->severity)->toBe(Severity::Medium)
        ->and(Advisory::firstWhere('external_id', 'ALAS2023-2026-3340')->aliases)->toHaveCount(8);
});

it('keeps following Amazon Linux 2 past its end of life, as the admin chose to', function () {
    fakeOsAdvisoryFeeds();

    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::AmazonLinux2);

    expect(Advisory::orderBy('external_id')->pluck('external_id')->all())->toBe(['ALAS2ECS-2026-152', 'ALAS2ECS-2026-153'])
        ->and(Advisory::firstWhere('external_id', 'ALAS2ECS-2026-153'))
        ->title->toBe('ALAS2ECS-2026-153: amazon-ecr-credential-helper')
        ->os_releases->toBe(['2']);
});

it('keeps an Ubuntu notice for the releases in standard support that get a free fix only', function () {
    fakeOsAdvisoryFeeds();

    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Ubuntu);

    // USN-8955-2 fixes only releases past their standard support, and USN-8994-1 fixes them in Ubuntu Pro only.
    expect(Advisory::orderBy('external_id')->pluck('external_id')->all())->toBe(['USN-7230-3', 'USN-8999-1', 'USN-9001-1'])
        ->and(Advisory::firstWhere('external_id', 'USN-7230-3'))
        ->title->toBe('USN-7230-3: FRR regression')
        ->os_releases->toBe(['24.04', '22.04'])
        ->fixed_packages->toEqual([
            ['release' => '22.04', 'name' => 'frr', 'version' => '8.1-1ubuntu1.17'],
            ['release' => '24.04', 'name' => 'frr', 'version' => '8.4.4-1.1ubuntu6.8'],
        ])
        ->url->toBe('https://ubuntu.com/security/notices/USN-7230-3')
        ->published_at->toIso8601String()->toBe('2026-11-05T13:39:13+00:00')
        ->and(Advisory::firstWhere('external_id', 'USN-9001-1'))
        ->os_releases->toBe(['26.10', '26.04', '24.04', '22.04'])
        ->aliases->toBe([])
        ->summary->not->toBeEmpty();
});

it('rates an Ubuntu notice by the highest Ubuntu priority of its CVE ids, read from OSV and cached', function () {
    fakeOsAdvisoryFeeds(['CVE-2026-59200' => 'high']);

    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Ubuntu);

    expect(Advisory::firstWhere('external_id', 'USN-8999-1')->severity)->toBe(Severity::High)
        ->and(Advisory::firstWhere('external_id', 'USN-7230-3')->severity)->toBe(Severity::Medium)
        ->and(Advisory::firstWhere('external_id', 'USN-9001-1')->severity)->toBe(Severity::Unknown)
        ->and(ubuntuPriorityRequests())->toHaveCount(12);

    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Ubuntu);

    expect(ubuntuPriorityRequests())->toHaveCount(12);
});

it('looks up a limited number of Ubuntu priorities per poll and rates a notice further on the next ones', function () {
    config(['psm.os_advisories.ubuntu_cve_lookups' => 5]);
    fakeOsAdvisoryFeeds(['CVE-2026-59205' => 'critical']);
    $sync = fn () => app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Ubuntu);

    $sync();
    expect(Advisory::firstWhere('external_id', 'USN-8999-1')->severity)->toBe(Severity::Medium);

    $sync();
    $sync();
    expect(Advisory::firstWhere('external_id', 'USN-8999-1')->severity)->toBe(Severity::Critical)
        ->and(ubuntuPriorityRequests())->toHaveCount(12);
});

it('leaves a CVE id OSV does not know unrated for a day, and a failed lookup for the next poll', function () {
    Http::fake([rtrim((string) config('psm.packages.osv_url'), '/').'/vulns/UBUNTU-CVE-2024-55553' => Http::response('Service Unavailable', 503)]);
    fakeOsAdvisoryFeeds();

    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Ubuntu);

    expect(Advisory::firstWhere('external_id', 'USN-7230-3')->severity)->toBe(Severity::Unknown)
        ->and(SourceHeartbeat::firstWhere('source', 'os:ubuntu')->last_error)->toBeNull();
});

it('reads at most three pages of 20 Ubuntu notices, and keeps the pages read when a later one fails', function () {
    Exceptions::fake();
    $notice = collect(json_decode(osFixture('usn-notices.json'), true)['notices'])->firstWhere('id', 'USN-9001-1');
    $notices = array_map(fn (int $number): array => [...$notice, 'id' => 'USN-'.(9100 - $number).'-1'], range(0, 44));
    Http::fake([UbuntuUsnFeed::URL.'*' => fn (ClientRequest $request) => (int) $request['offset'] === 20
        ? Http::response('Service Unavailable', 503)
        : Http::response(['notices' => array_slice($notices, (int) $request['offset'], (int) $request['limit'])])]);
    fakeOsAdvisoryFeeds();

    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Ubuntu);

    expect(Advisory::count())->toBe(20)
        ->and(Http::recorded(fn (ClientRequest $request): bool => str_starts_with($request->url(), UbuntuUsnFeed::URL))->map(fn (array $pair): string => $pair[0]['offset'].'/'.$pair[0]['limit'])->unique()->values()->all())->toBe(['0/20', '20/20']);
    Exceptions::assertReported(RequestException::class);
});

it('reads the CVE ids, releases and fixed packages of a Debian advisory from the security tracker', function () {
    fakeOsAdvisoryFeeds();

    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Debian);

    expect(Advisory::orderBy('external_id')->pluck('external_id')->all())->toBe(['DSA-6600-2', 'DSA-6615-1', 'DSA-6616-1'])
        ->and(Advisory::firstWhere('external_id', 'DSA-6616-1'))
        ->title->toBe('DSA-6616-1: linux - security update')
        ->severity->toBe(Severity::Unknown)
        ->aliases->toBe(['CVE-2026-100080', 'CVE-2026-100081', 'CVE-2026-100082'])
        ->os_releases->toBe(['13'])
        ->fixed_packages->toEqual([['release' => '13', 'name' => 'linux', 'version' => '6.12.116-1']])
        ->url->toBe('https://lists.debian.org/debian-security-announce/2026/msg00536.html')
        ->links->toBe(['https://security-tracker.debian.org/tracker/DSA-6616-1'])
        ->published_at->toIso8601String()->toBe('2026-11-08T00:00:00+00:00')
        ->and(Advisory::firstWhere('external_id', 'DSA-6600-2')->aliases)->toBe([]);
});

it('stores a Debian advisory whose tracker page fails without its details and completes it on a later poll', function () {
    Exceptions::fake();
    Http::fake([DebianDsaFeed::TRACKER_URL.'DSA-6616-1' => Http::sequence()->push('Bad Gateway', 502)->push('Bad Gateway', 502)->push('Bad Gateway', 502)->push(osFixture('debian-tracker-DSA-6616-1.html'))]);
    fakeOsAdvisoryFeeds();

    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Debian);

    expect(Advisory::firstWhere('external_id', 'DSA-6616-1'))->aliases->toBe([])->os_releases->toBeNull();
    Exceptions::assertReported(RequestException::class);

    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Debian);

    expect(Advisory::firstWhere('external_id', 'DSA-6616-1'))->aliases->toHaveCount(3)->os_releases->toBe(['13']);
});

it('treats the advisories a feed lists on its first poll as history and the later ones as news', function () {
    $this->travelTo('2026-11-05 00:00:00');
    fakeOsAdvisoryFeeds();
    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Ubuntu);

    $this->travelTo('2026-11-09 10:00:00');
    $advisories = app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Ubuntu);

    expect(Advisory::firstWhere('external_id', 'USN-8999-1')->is_backfill)->toBeTrue()
        ->and(Advisory::firstWhere('external_id', 'USN-7230-3')->is_backfill)->toBeFalse()
        ->and(Advisory::firstWhere('external_id', 'USN-9001-1')->is_backfill)->toBeFalse()
        ->and(collect($advisories)->filter->wasRecentlyCreated->pluck('external_id')->sort()->values()->all())->toBe(['USN-7230-3', 'USN-9001-1']);
});

it('does not treat an advisory of the first poll as history when CISA added one of its CVE ids recently', function () {
    KnownExploitedVulnerability::factory()->create(['cve_id' => 'CVE-2026-108976', 'date_added' => '2026-11-02']);
    fakeOsAdvisoryFeeds();

    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::AmazonLinux2023);

    expect(Advisory::firstWhere('external_id', 'ALAS2023-2026-3338'))
        ->is_backfill->toBeFalse()
        ->isKnownExploited()->toBeTrue()
        ->severity->toBe(Severity::High)
        ->and(Advisory::firstWhere('external_id', 'ALAS2023-2026-3339')->is_backfill)->toBeTrue();
});

it('keeps the releases read before when endoflife.date fails', function () {
    Exceptions::fake();
    $isFailing = false;
    Http::fake(['https://endoflife.date/api/v1/products/*' => function () use (&$isFailing) {
        return $isFailing ? Http::response('Bad Gateway', 502) : Http::response(osFixture('endoflife-ubuntu.json'));
    }]);
    fakeOsAdvisoryFeeds();
    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Ubuntu);
    $isFailing = true;

    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Ubuntu);

    expect(Advisory::firstWhere('external_id', 'USN-7230-3')->os_releases)->toBe(['24.04', '22.04']);
    Exceptions::assertReported(RequestException::class);
});

it('records a failing feed on its heartbeat and throws', function () {
    Http::fake([DebianDsaFeed::URL => Http::response('Service Unavailable', 503)]);
    fakeOsAdvisoryFeeds();

    expect(fn () => app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::Debian))->toThrow(RequestException::class);

    expect(SourceHeartbeat::firstWhere('source', 'os:debian'))
        ->last_success_at->toBeNull()
        ->last_failure_at->not->toBeNull()
        ->last_error->toContain('RequestException')
        ->localizedName()->toBe('Tin bảo mật Debian');
});

it('stores nothing new when polled again, and follows what the distribution changed', function () {
    fakeOsAdvisoryFeeds();
    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::AmazonLinux2023);
    Advisory::where('external_id', 'ALAS2023-2026-3338')->update(['severity' => Severity::Low]);

    app(SyncOsAdvisoryFeed::class)->handle(LinuxDistribution::AmazonLinux2023);

    expect(Advisory::count())->toBe(3)
        ->and(Advisory::firstWhere('external_id', 'ALAS2023-2026-3338')->severity)->toBe(Severity::High)
        ->and(SourceHeartbeat::firstWhere('source', 'os:amazon-linux-2023'))->last_success_at->not->toBeNull()->threshold_minutes->toBe(180);
});

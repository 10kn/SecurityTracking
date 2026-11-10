<?php

use App\Actions\SyncNoticeFeed;
use App\Enums\IncidentSuggestionReason;
use App\Enums\Severity;
use App\Enums\SeverityBasis;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\IncidentSuggestion;
use App\Services\Notices\NoticeClassification;
use App\Services\Notices\NoticeEntry;
use App\Services\Notices\PhpNetNoticeFeed;
use App\Services\Notices\VendorSecurityAdvisories;
use App\Services\Notices\VendorSecurityAdvisory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

const PHP_SRC_ADVISORIES = 'https://api.github.com/repos/php/php-src/security-advisories*';

beforeEach(function () {
    $this->travelTo('2026-10-23 10:00:00');
    config(['psm.notices.vendor_advisories.url' => 'https://api.github.com/repos/{repository}/security-advisories']);
});

/**
 * The php-src advisories of tests/Fixtures/notices/php-src-security-advisories.json: the real ones of the 24 September
 * and 21 October 2026 releases, and one of July, fetched on 2026-11-10 and trimmed to the fields that are read.
 *
 * @return list<array<string, mixed>>
 */
function phpSrcAdvisories(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/notices/php-src-security-advisories.json')), true);
}

/**
 * The php.net feed with the announcement of PHP 8.4.26 too, published the same day as that of PHP 8.3.35 and, like it,
 * naming no CVE.
 */
function phpFeedWithTwoReleasesOfOneDay(): string
{
    $feed = noticeFixture('php-feed.atom');
    $entry = Str::between($feed, '<entry xmlns="http://www.w3.org/2005/Atom" xml:base="entries/2026-09-24-2.xml">', '</entry>');
    $other = str_replace(['8.3.35', 'PHP 8.3 users', '2026-09-24-2', 'version=8.3', '09:51:44'], ['8.4.26', 'PHP 8.4 users', '2026-09-24-5', 'version=8.4', '15:26:06'], $entry);

    return str_replace('<entry xmlns="http://www.w3.org/2005/Atom" xml:base="entries/2026-09-24-2.xml">', '<entry xmlns="http://www.w3.org/2005/Atom" xml:base="entries/2026-09-24-5.xml">'.$other.'</entry>'."\n".'  <entry xmlns="http://www.w3.org/2005/Atom" xml:base="entries/2026-09-24-2.xml">', $feed);
}

/**
 * The classifier reads the version a release announcement updates to from its title, as it does from its words.
 */
function classifyReleaseAnnouncements(): void
{
    classifyNoticesWith(fn (NoticeEntry $entry): NoticeClassification => preg_match('/^PHP (\d+\.\d+\.\d+) Released!$/', $entry->title, $match) === 1
        ? new NoticeClassification(isSecurityNews: true, severity: Severity::Unknown, affectedBranches: [implode('.', array_slice(explode('.', $match[1]), 0, 2))], patchVersions: [$match[1]])
        : new NoticeClassification(isSecurityNews: false, severity: Severity::Unknown));
}

it('finds the advisories a release fixes, with their ids, the vendor\'s severity and their highest CVSS score', function () {
    Http::preventStrayRequests();
    Http::fake([PHP_SRC_ADVISORIES => Http::response(phpSrcAdvisories())]);

    $advisories = app(VendorSecurityAdvisories::class)->fixedIn(Technology::Php, ['8.3.35']);

    expect(array_map(fn (VendorSecurityAdvisory $advisory): array => $advisory->toArray(), $advisories))->toBe([
        ['ghsa_id' => 'GHSA-7875-c8px-7q5f', 'cve_id' => 'CVE-2026-93682', 'severity' => 'medium', 'cvss_score' => 5.3, 'patched_versions' => ['8.2.34', '8.3.35', '8.4.26', '8.5.11']],
        ['ghsa_id' => 'GHSA-9f67-6fw4-hpfp', 'cve_id' => 'CVE-2026-17545', 'severity' => 'medium', 'cvss_score' => 6.9, 'patched_versions' => ['8.2.34', '8.3.35', '8.4.26', '8.5.11']],
        ['ghsa_id' => 'GHSA-ch8v-r6jh-4vvr', 'cve_id' => null, 'severity' => 'low', 'cvss_score' => 3.7, 'patched_versions' => ['8.2.34', '8.3.35', '8.4.25', '8.5.10']],
        ['ghsa_id' => 'GHSA-rgrp-mwpx-f6rm', 'cve_id' => 'CVE-2026-91765', 'severity' => 'high', 'cvss_score' => 7.5, 'patched_versions' => ['8.2.34', '8.3.35', '8.4.26', '8.5.11']],
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/php/php-src/security-advisories?state=published&sort=published&direction=desc&per_page=100'
        && $request->hasHeader('Accept', 'application/vnd.github+json'));
});

it('leaves out withdrawn advisories and those without a patched release', function () {
    $advisories = phpSrcAdvisories();
    $advisories[0]['withdrawn_at'] = '2026-10-22T08:00:00Z';
    $advisories[1]['vulnerabilities'] = [['package' => ['ecosystem' => 'php', 'name' => 'php-src'], 'vulnerable_version_range' => '<8.4.27', 'patched_versions' => null]];
    Http::preventStrayRequests();
    Http::fake([PHP_SRC_ADVISORIES => Http::response($advisories)]);

    expect(app(VendorSecurityAdvisories::class)->fixedIn(Technology::Php, ['8.4.27']))->toBe([]);
});

it('asks nothing when there is no version, no repository or no address', function () {
    Http::preventStrayRequests();
    Http::fake();
    $advisories = app(VendorSecurityAdvisories::class);

    expect($advisories->fixedIn(Technology::Php, []))->toBe([])
        ->and($advisories->fixedIn(Technology::Nodejs, ['24.22.1']))->toBe([]);

    config(['psm.notices.vendor_advisories.url' => '']);

    expect($advisories->fixedIn(Technology::Php, ['8.3.35']))->toBe([]);
    Http::assertNothingSent();
});

it('reads the list once per instance, and asks GitHub whether it changed since the last read', function () {
    Http::preventStrayRequests();
    Http::fakeSequence(PHP_SRC_ADVISORIES)
        ->push(phpSrcAdvisories(), 200, ['ETag' => 'W/"v1"'])
        ->push(null, 304, ['ETag' => 'W/"v1"']);

    $first = app(VendorSecurityAdvisories::class);
    $first->fixedIn(Technology::Php, ['8.3.35']);
    $first->fixedIn(Technology::Php, ['8.4.27']);

    expect(app(VendorSecurityAdvisories::class)->fixedIn(Technology::Php, ['8.4.27']))->toHaveCount(2);

    $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
    expect($sent)->toHaveCount(2)
        ->and($sent[0]->hasHeader('If-None-Match'))->toBeFalse()
        ->and($sent[1]->header('If-None-Match'))->toBe(['W/"v1"']);
});

it('reports a failed read and keeps the list read before', function () {
    Sleep::fake();
    Exceptions::fake();
    Http::preventStrayRequests();
    Http::fakeSequence(PHP_SRC_ADVISORIES)
        ->push(phpSrcAdvisories(), 200, ['ETag' => 'W/"v1"'])
        ->whenEmpty(Http::response('Service Unavailable', 503));

    app(VendorSecurityAdvisories::class)->fixedIn(Technology::Php, ['8.3.35']);

    expect(app(VendorSecurityAdvisories::class)->fixedIn(Technology::Php, ['8.4.27']))->toHaveCount(2);
    Exceptions::assertReported(RequestException::class);
});

it('gives a release announcement the ids and the severity of the advisories its version fixes', function () {
    Http::preventStrayRequests();
    Http::fake([
        PhpNetNoticeFeed::URL => Http::response(noticeFixture('php-feed.atom')),
        PHP_SRC_ADVISORIES => Http::response(phpSrcAdvisories()),
    ]);
    classifyReleaseAnnouncements();

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', 'PHP 8.3.35 Released!')->sole())
        ->aliases->toBe(['CVE-2026-93682', 'CVE-2026-17545', 'CVE-2026-91765', 'GHSA-7875-c8px-7q5f', 'GHSA-9f67-6fw4-hpfp', 'GHSA-ch8v-r6jh-4vvr', 'GHSA-rgrp-mwpx-f6rm'])
        ->severity->toBe(Severity::High)
        ->severity_basis->toBe(SeverityBasis::VendorAdvisory)
        ->cvss_score->toBe('7.5')
        ->is_dismissed->toBeFalse()
        ->resolved_cve_ids->toBe([
            'GHSA-7875-c8px-7q5f' => ['cves' => ['CVE-2026-93682'], 'source' => 'vendor'],
            'GHSA-9f67-6fw4-hpfp' => ['cves' => ['CVE-2026-17545'], 'source' => 'vendor'],
            'GHSA-rgrp-mwpx-f6rm' => ['cves' => ['CVE-2026-91765'], 'source' => 'vendor'],
        ])
        ->and(Advisory::where('title', 'PHP 8.3.36 Released!')->sole())
        ->aliases->toContain('GHSA-w8hw-w9fx-j79w', 'GHSA-6wmf-4rv5-p3rp')
        ->severity->toBe(Severity::Critical)
        ->and(Advisory::where('title', 'Dutch PHP Conference 2027')->sole())
        ->aliases->toBe([])
        ->is_dismissed->toBeTrue();
});

it('suggests putting the announcements of one release together through the advisories they share', function () {
    Http::preventStrayRequests();
    Http::fake([
        PhpNetNoticeFeed::URL => Http::response(phpFeedWithTwoReleasesOfOneDay()),
        PHP_SRC_ADVISORIES => Http::response(phpSrcAdvisories()),
    ]);
    classifyReleaseAnnouncements();

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    $php84 = Advisory::where('title', 'PHP 8.4.26 Released!')->sole();
    $php83 = Advisory::where('title', 'PHP 8.3.35 Released!')->sole();

    expect($php84->incident_id)->not->toBe($php83->incident_id)
        ->and($php84->severity)->toBe(Severity::High)
        ->and($php84->incident->severity)->toBe(Severity::High)
        ->and(IncidentSuggestion::sole())
        ->advisory_id->toBe($php83->id)
        ->incident_id->toBe($php84->incident_id)
        ->reason->toBe(IncidentSuggestionReason::SharedId);
});

it('keeps an announcement as it was when GitHub has no advisory for its version', function () {
    Http::preventStrayRequests();
    Http::fake([
        PhpNetNoticeFeed::URL => Http::response(noticeFixture('php-feed.atom')),
        PHP_SRC_ADVISORIES => Http::response([]),
    ]);
    classifyReleaseAnnouncements();

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', 'PHP 8.3.35 Released!')->sole())
        ->aliases->toBe([])
        ->severity->toBe(Severity::Unknown)
        ->severity_basis->toBeNull()
        ->cvss_score->toBeNull();
});

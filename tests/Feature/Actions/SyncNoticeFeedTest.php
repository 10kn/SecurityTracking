<?php

use App\Actions\SyncNoticeFeed;
use App\Enums\AdvisoryKind;
use App\Enums\ChatNotificationKind;
use App\Enums\Severity;
use App\Enums\SeverityBasis;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\IncidentTeamNotice;
use App\Models\SourceHeartbeat;
use App\Models\Team;
use App\Services\Notices\NoticeClassification;
use App\Services\Notices\NoticeEntry;
use App\Services\Notices\PhpNetNoticeFeed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->travelTo('2026-10-23 10:00:00');
});

it('stores the security news of php.net as pre-notices and leaves release announcements to the catalog sync', function () {
    fakeNoticeFeeds();
    $php = Technology::Php;

    app(SyncNoticeFeed::class)->handle($php);

    expect(Advisory::orderByDesc('published_at')->pluck('title')->all())->toBe([
        'Security advisory: Critical remote code execution vulnerabilities, update now',
        'Upcoming Critical PHP Security Release',
        'PHPKonf 2025 Baku',
    ]);

    $preNotice = Advisory::where('external_id', 'https://www.php.net/archive/2026.php#2026-10-20-1')->sole();
    expect($preNotice)
        ->kind->toBe(AdvisoryKind::PreNotice)
        ->source->toBe('notice:php')
        ->technology->toBe($php)
        ->severity->toBe(Severity::Critical)
        ->cvss_score->toBe('9.2')
        ->url->toBe('https://www.php.net/archive/2026.php#2026-10-20-1')
        ->published_at->toIso8601String()->toBe('2026-10-20T20:32:00+00:00')
        ->summary->toContain('advance notice of an upcoming security release', '21 October 2026 at 14:00 UTC')
        ->patch_versions->toBeNull()
        ->and(Advisory::where('title', 'like', 'Security advisory%')->sole())
        ->patch_versions->toBe(['8.5.12', '8.4.27', '8.3.36', '8.2.35'])
        ->aliases->toBe(['CVE-2026-109618', 'CVE-2026-109619', 'GHSA-w8hw-w9fx-j79w', 'GHSA-6wmf-4rv5-p3rp'])
        ->links->toContain('https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w', 'https://www.php.net/downloads.php?source=Y')
        ->summary->toHaveLength(503);
});

it('keeps every entry of the nodejs.org vulnerability feed and only the security news of ruby-lang.org', function () {
    fakeNoticeFeeds();

    app(SyncNoticeFeed::class)->handle(Technology::Nodejs);
    app(SyncNoticeFeed::class)->handle(Technology::Ruby);

    expect(Advisory::where('source', 'notice:nodejs')->count())->toBe(3)
        ->and(Advisory::where('source', 'notice:ruby')->orderBy('published_at')->pluck('title')->all())->toBe([
            'Security advisories: CVE-2026-80212 and CVE-2026-80213',
            "Regexp compilation fixes related to GitLab's CVE-2026-89078, CVE-2026-93577, CVE-2026-97229 and CVE-2026-110264",
        ]);
});

it('follows the configured keywords', function () {
    config(['psm.notices.keywords' => ['conference']]);
    fakeNoticeFeeds();

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::pluck('title')->all())->toContain('Dutch PHP Conference 2027')->not->toContain('Upcoming Critical PHP Security Release');
});

it('is idempotent and follows edits the vendor makes to an entry', function () {
    $feed = noticeFixture('php-feed.atom');
    Http::fake([PhpNetNoticeFeed::URL => function () use (&$feed) {
        return Http::response($feed);
    }]);
    $php = Technology::Php;
    app(SyncNoticeFeed::class)->handle($php);
    $before = Advisory::orderBy('id')->get(['id', 'updated_at'])->toArray();

    $this->travel(15)->minutes();
    $advisories = app(SyncNoticeFeed::class)->handle($php);

    expect(Advisory::orderBy('id')->get(['id', 'updated_at'])->toArray())->toBe($before)
        ->and(collect($advisories)->contains(fn (Advisory $advisory): bool => $advisory->wasRecentlyCreated || $advisory->wasChanged()))->toBeFalse();

    $feed = str_replace('Upcoming Critical PHP Security Release', 'Upcoming Critical PHP Security Release (updated)', $feed);
    app(SyncNoticeFeed::class)->handle($php);

    expect(Advisory::count())->toBe(3)
        ->and(Advisory::where('external_id', 'https://www.php.net/archive/2026.php#2026-10-20-1')->value('title'))->toBe('Upcoming Critical PHP Security Release (updated)');
});

it('rates an entry by its CVSS score when the vendor gives no rating, and records what gave the severity', function () {
    $feed = str_replace('Upcoming Critical PHP Security Release', 'Upcoming PHP Security Release', noticeFixture('php-feed.atom'));
    $feed = str_replace('critical', 'serious', $feed);
    Http::fake([PhpNetNoticeFeed::URL => Http::response($feed)]);

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('external_id', 'https://www.php.net/archive/2026.php#2026-10-20-1')->sole())
        ->severity->toBe(Severity::Critical)
        ->severity_basis->toBe(SeverityBasis::Cvss)
        ->cvss_score->toBe('9.2');
});

it('says the vendor gave the severity when its words and the CVSS score agree', function () {
    fakeNoticeFeeds();

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole()->severity_basis)->toBe(SeverityBasis::Vendor)
        ->and(Advisory::where('title', 'PHPKonf 2025 Baku')->sole())
        ->severity->toBe(Severity::Unknown)
        ->severity_basis->toBeNull();
});

it('marks what a feed lists on its first poll as history, except critical news of the last days', function () {
    fakeNoticeFeeds();
    $php = Technology::Php;

    app(SyncNoticeFeed::class)->handle($php);

    expect(Advisory::where('is_backfill', false)->orderBy('published_at')->pluck('title')->all())->toBe([
        'Upcoming Critical PHP Security Release',
        'Security advisory: Critical remote code execution vulnerabilities, update now',
    ]);

    Advisory::query()->delete();
    $this->travel(15)->minutes();
    app(SyncNoticeFeed::class)->handle($php);

    expect(Advisory::where('is_backfill', true)->count())->toBe(0);
});

it('sends an entry of the first poll after all when it turns critical later', function () {
    Exceptions::fake();
    fakeNoticeFeeds();
    $fails = true;
    classifyNoticesWith(function () use (&$fails): NoticeClassification {
        return $fails ? throw new RuntimeException('Overloaded') : new NoticeClassification(isSecurityNews: true, severity: Severity::Critical);
    });

    app(SyncNoticeFeed::class)->handle(Technology::Nodejs);
    $releases = Advisory::where('title', 'Wednesday, October 21, 2026 Security Releases')->sole();
    expect($releases)->is_backfill->toBeTrue()->severity->toBe(Severity::Unknown);

    $fails = false;
    $this->travel(15)->minutes();
    app(SyncNoticeFeed::class)->handle(Technology::Nodejs);

    expect($releases->refresh())->is_backfill->toBeFalse()->severity->toBe(Severity::Critical)
        ->and(Advisory::where('title', 'Wednesday, July 29, 2026 Security Releases')->sole()->is_backfill)->toBeTrue();
});

it('records when the classifier started failing on an entry and clears it once the entry is classified', function () {
    Exceptions::fake();
    fakeNoticeFeeds();
    $fails = true;
    classifyNoticesWith(function () use (&$fails): NoticeClassification {
        return $fails ? throw new RuntimeException('Overloaded') : new NoticeClassification(isSecurityNews: true, severity: Severity::Critical);
    });
    $php = Technology::Php;

    app(SyncNoticeFeed::class)->handle($php);
    $this->travel(15)->minutes();
    app(SyncNoticeFeed::class)->handle($php);

    $preNotice = Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole();
    expect($preNotice->classification_failed_at->toDateTimeString())->toBe('2026-10-23 10:00:00');

    $fails = false;
    app(SyncNoticeFeed::class)->handle($php);

    expect($preNotice->refresh()->classification_failed_at)->toBeNull();
});

it('no longer holds a dismissal once the vendor edits the entry and the classifier cannot read the new text', function () {
    Exceptions::fake();
    $feed = noticeFixture('php-feed.atom');
    Http::fake([PhpNetNoticeFeed::URL => function () use (&$feed) {
        return Http::response($feed);
    }]);
    $fails = false;
    classifyNoticesWith(function () use (&$fails): NoticeClassification {
        return $fails ? throw new RuntimeException('Overloaded') : new NoticeClassification(isSecurityNews: false, severity: Severity::Unknown);
    });
    config(['psm.notices.relevant_days' => 1000]);
    app(SyncNoticeFeed::class)->handle(Technology::Php);
    expect(Advisory::where('title', 'PHPKonf 2025 Baku')->sole()->is_dismissed)->toBeTrue();

    $fails = true;
    $feed = str_replace('PHPKonf 2025 Baku', 'PHPKonf 2025 Baku: security notice', $feed);
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', 'PHPKonf 2025 Baku: security notice')->sole()->is_dismissed)->toBeFalse();
});

it('records a successful poll on the feed heartbeat', function () {
    fakeNoticeFeeds();

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(SourceHeartbeat::where('source', 'notice:php')->sole())
        ->name->toBe('Tin bảo mật php.net')
        ->threshold_minutes->toBe(120)
        ->last_success_at->not->toBeNull()
        ->last_error->toBeNull();
});

it('records a failed poll on the feed heartbeat and rethrows', function () {
    Sleep::fake();
    Http::fake([PhpNetNoticeFeed::URL => Http::response('Service Unavailable', 503)]);
    $php = Technology::Php;

    expect(fn () => app(SyncNoticeFeed::class)->handle($php))->toThrow(RequestException::class);

    expect(SourceHeartbeat::where('source', 'notice:php')->sole())
        ->last_success_at->toBeNull()
        ->last_failure_at->not->toBeNull()
        ->last_error->toStartWith('RequestException')
        ->and(Advisory::count())->toBe(0);
});

it('classifies the entries it keeps, the history of the feed included, and stores what the classifier read', function () {
    fakeNoticeFeeds();
    $classified = classifyNoticesWith(fn (NoticeEntry $entry): NoticeClassification => new NoticeClassification(
        isSecurityNews: true,
        severity: Severity::Critical,
        plannedReleaseAt: CarbonImmutable::parse('2026-10-21 14:00:00'),
        affectedBranches: ['8.3', '8.4'],
        summary: 'PHP sẽ vá hai lỗ hổng nghiêm trọng.',
    ));

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect($classified->getArrayCopy())->toEqualCanonicalizing([
        'Security advisory: Critical remote code execution vulnerabilities, update now',
        'Upcoming Critical PHP Security Release',
        'PHPKonf 2025 Baku',
    ])->and(Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole())
        ->classified_by->toBe('claude-haiku-5-5')
        ->classified_severity->toBe(Severity::Critical)
        ->planned_release_at->toIso8601String()->toBe('2026-10-21T14:00:00+00:00')
        ->affected_branches->toBe(['8.3', '8.4'])
        ->localized_summary->toBe('PHP sẽ vá hai lỗ hổng nghiêm trọng.')
        ->is_dismissed->toBeFalse()
        ->and(Advisory::where('title', 'PHPKonf 2025 Baku')->sole())
        ->classified_by->toBe('claude-haiku-5-5')
        ->is_backfill->toBeTrue();
});

it('classifies an entry again only when the vendor edits it', function () {
    $feed = noticeFixture('php-feed.atom');
    Http::fake([PhpNetNoticeFeed::URL => function () use (&$feed) {
        return Http::response($feed);
    }]);
    $classified = classifyNoticesWith(fn (): NoticeClassification => new NoticeClassification(isSecurityNews: true, severity: Severity::Critical));
    $php = Technology::Php;
    app(SyncNoticeFeed::class)->handle($php);

    $this->travel(15)->minutes();
    $advisories = app(SyncNoticeFeed::class)->handle($php);

    expect($classified)->toHaveCount(3)
        ->and(collect($advisories)->contains(fn (Advisory $advisory): bool => $advisory->wasRecentlyCreated || $advisory->wasChanged()))->toBeFalse();

    $feed = str_replace('scheduled for Wednesday, 21 October 2026 at 14:00 UTC', 'scheduled for Thursday, 22 October 2026 at 14:00 UTC', $feed);
    app(SyncNoticeFeed::class)->handle($php);

    expect($classified)->toHaveCount(4)
        ->and($classified[3])->toBe('Upcoming Critical PHP Security Release');
});

it('lets the classifier replace the severity the vendor\'s words gave, but not lower the CVSS score', function () {
    fakeNoticeFeeds();
    classifyNoticesWith(fn (NoticeEntry $entry): NoticeClassification => new NoticeClassification(
        isSecurityNews: true,
        severity: str_starts_with($entry->title, 'PHPKonf') ? Severity::Critical : Severity::Low,
    ));

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', 'Security advisory: Critical remote code execution vulnerabilities, update now')->sole())
        ->severity->toBe(Severity::Low)
        ->severity_basis->toBe(SeverityBasis::Classifier)
        ->and(Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole())
        ->severity->toBe(Severity::Critical)
        ->severity_basis->toBe(SeverityBasis::Cvss)
        ->and(Advisory::where('title', 'PHPKonf 2025 Baku')->value('severity'))->toBe(Severity::Critical);
});

it('rates an entry by the vendor\'s words again once the vendor edits it, until the classifier reads the new text', function () {
    Exceptions::fake();
    $feed = noticeFixture('php-feed.atom');
    Http::fake([PhpNetNoticeFeed::URL => function () use (&$feed) {
        return Http::response($feed);
    }]);
    $fails = false;
    classifyNoticesWith(function () use (&$fails): NoticeClassification {
        return $fails ? throw new RuntimeException('Overloaded') : new NoticeClassification(isSecurityNews: true, severity: Severity::Low);
    });
    $title = 'Security advisory: Critical remote code execution vulnerabilities, update now';
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', $title)->value('severity'))->toBe(Severity::Low);

    $fails = true;
    $feed = str_replace('update now</title>', 'update today</title>', $feed);
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', 'Security advisory: Critical remote code execution vulnerabilities, update today')->sole())
        ->severity->toBe(Severity::Critical)
        ->severity_basis->toBe(SeverityBasis::Vendor);
});

it('dismisses an entry the classifier finds is not security news whatever its words, unless its CVSS score says otherwise', function () {
    fakeNoticeFeeds();
    classifyNoticesWith(fn (): NoticeClassification => new NoticeClassification(isSecurityNews: false, severity: Severity::Unknown));

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('is_dismissed', true)->pluck('title')->all())->toEqualCanonicalizing([
        'Security advisory: Critical remote code execution vulnerabilities, update now',
        'PHPKonf 2025 Baku',
    ])->and(Advisory::where('title', 'PHPKonf 2025 Baku')->sole())
        ->severity->toBe(Severity::Unknown)
        ->severity_basis->toBeNull();
});

it('keeps the keyword result when the classifier fails, and classifies the entry on the next poll', function () {
    Exceptions::fake();
    fakeNoticeFeeds();
    $fails = true;
    $classified = classifyNoticesWith(function () use (&$fails): NoticeClassification {
        return $fails ? throw new RuntimeException('Overloaded') : new NoticeClassification(isSecurityNews: true, severity: Severity::Critical);
    });
    $php = Technology::Php;

    app(SyncNoticeFeed::class)->handle($php);

    Exceptions::assertReported(RuntimeException::class);
    expect(Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole())
        ->severity->toBe(Severity::Critical)
        ->classified_by->toBeNull()
        ->and(SourceHeartbeat::where('source', 'notice:php')->value('last_error'))->toBeNull();

    $fails = false;
    $this->travel(15)->minutes();
    app(SyncNoticeFeed::class)->handle($php);

    expect($classified)->toHaveCount(6)
        ->and(Advisory::whereNotNull('classified_by')->count())->toBe(3);
});

it('does not call the classifier when no API key is configured', function () {
    fakeNoticeFeeds();

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::whereNotNull('classified_by')->count())->toBe(0)
        ->and(Advisory::count())->toBe(3);
});

it('classifies an old pre-notice that was never classified, and an edited one when a team was sent a card about its incident', function (bool $wasSent, ?string $contentHash, array $classified, array $branches) {
    $this->travelTo('2026-11-30 10:00:00');
    $feed = noticeFixture('php-feed.atom');
    Http::fake([PhpNetNoticeFeed::URL => function () use (&$feed) {
        return Http::response($feed);
    }]);
    $titles = classifyNoticesWith(fn (): NoticeClassification => new NoticeClassification(isSecurityNews: true, severity: Severity::Critical, affectedBranches: ['8.3']));
    app(SyncNoticeFeed::class)->handle(Technology::Php);
    $titles->exchangeArray([]);
    $notice = Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole();
    $notice->update(['content_hash' => $contentHash]);

    if ($wasSent) {
        $team = Team::factory()->create();
        $card = ChatNotification::factory()->for($team)->sent()->create(['kind' => ChatNotificationKind::PreNotice]);
        IncidentTeamNotice::create(['team_id' => $team->id, 'advisory_id' => $notice->id, 'severity' => Severity::Critical, 'reason' => 'new', 'chat_notification_id' => $card->id]);
    }

    $feed = str_replace('It addresses two critical vulnerabilities', 'It addresses two critical vulnerabilities of PHP 8.3', $feed);
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect($titles->getArrayCopy())->toBe($classified)
        ->and($notice->refresh()->affected_branches)->toBe($branches);
})->with([
    'told, edited' => [true, 'classified when it was fresh', ['Upcoming Critical PHP Security Release'], ['8.3']],
    'told, never classified' => [true, null, ['Upcoming Critical PHP Security Release'], ['8.3']],
    'never told, edited' => [false, 'classified when it was fresh', [], ['8.3']],
    'never told, never classified' => [false, null, ['Upcoming Critical PHP Security Release'], ['8.3']],
]);

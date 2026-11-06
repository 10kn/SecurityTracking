<?php

use Anthropic\Core\Exceptions\APIStatusException;
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
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->travelTo('2026-10-23 10:00:00');
});

it('stores every entry of php.net published within the collection window as a pre-notice, release announcements included', function () {
    fakeNoticeFeeds();
    $php = Technology::Php;

    app(SyncNoticeFeed::class)->handle($php);

    expect(Advisory::orderByDesc('published_at')->pluck('title')->all())->toBe([
        'Security advisory: Critical remote code execution vulnerabilities, update now',
        'PHP 8.3.36 Released!',
        'Upcoming Critical PHP Security Release',
        'PHP 8.6.0 RC3 available for testing',
        'PHP 8.3.35 Released!',
        'Dutch PHP Conference 2027',
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
        ->aliases->toBe(['CVE-2026-109618', 'CVE-2026-109619', 'GHSA-w8hw-w9fx-j79w', 'GHSA-6wmf-4rv5-p3rp'])
        ->links->toContain('https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w', 'https://www.php.net/downloads.php?source=Y')
        ->summary->toHaveLength(503);
});

it('stores every recent entry of the nodejs.org vulnerability feed and of the ruby-lang.org news', function () {
    fakeNoticeFeeds();

    app(SyncNoticeFeed::class)->handle(Technology::Nodejs);
    app(SyncNoticeFeed::class)->handle(Technology::Ruby);

    expect(Advisory::where('source', 'notice:nodejs')->count())->toBe(2)
        ->and(Advisory::where('source', 'notice:ruby')->orderBy('published_at')->pluck('title')->all())->toBe([
            'Security advisories: CVE-2026-80212 and CVE-2026-80213',
            'Ruby 4.0.8 Released',
            "Regexp compilation fixes related to GitLab's CVE-2026-89078, CVE-2026-93577, CVE-2026-97229 and CVE-2026-110264",
            'Ruby 3.3.13 Released',
        ]);
});

it('reads nothing from the words without the classifier: an entry without a CVSS score or a KEV listing waits for it', function () {
    fakeNoticeFeeds();

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('is_dismissed', false)->pluck('title')->all())->toBe(['Upcoming Critical PHP Security Release'])
        ->and(Advisory::where('title', 'like', 'Security advisory%')->sole())
        ->severity->toBe(Severity::Unknown)
        ->severity_basis->toBeNull()
        ->is_exploited->toBeFalse()
        ->patch_versions->toBeNull()
        ->is_dismissed->toBeTrue();
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

    expect(Advisory::count())->toBe(6)
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

it('takes no severity from the vendor\'s words, only from the CVSS score while the classifier has not read the entry', function () {
    fakeNoticeFeeds();

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole()->severity_basis)->toBe(SeverityBasis::Cvss)
        ->and(Advisory::where('title', 'Dutch PHP Conference 2027')->sole())
        ->severity->toBe(Severity::Unknown)
        ->severity_basis->toBeNull();
});

it('marks what a feed lists on its first poll as history, except critical news of the last days', function () {
    fakeNoticeFeeds();
    classifyNoticeFixtures();
    $php = Technology::Php;

    app(SyncNoticeFeed::class)->handle($php);

    expect(Advisory::where('is_backfill', false)->orderBy('published_at')->pluck('title')->all())->toBe([
        'Upcoming Critical PHP Security Release',
        'PHP 8.3.36 Released!',
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

it('tells the admin once when the classifier starts failing, with the error, and again once it works', function () {
    Exceptions::fake();
    fakeGoogleChat();
    fakeNoticeFeeds();
    $fails = true;
    classifyNoticesWith(function () use (&$fails): NoticeClassification {
        return $fails
            ? throw APIStatusException::from(new Request('POST', 'https://api.anthropic.com/v1/messages'), new Response(401, [], (string) json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']])))
            : new NoticeClassification(isSecurityNews: true, severity: Severity::Critical);
    });

    app(SyncNoticeFeed::class)->handle(Technology::Php);
    $this->travel(15)->minutes();
    app(SyncNoticeFeed::class)->handle(Technology::Php);
    app(SyncNoticeFeed::class)->handle(Technology::Nodejs);

    expect(chatMessagesTo('ADMIN'))->toHaveCount(1)
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('AI phân loại tin bảo mật đang lỗi', 'Model: claude-haiku-5-5', 'Lỗi: HTTP 401: invalid x-api-key', 'Kiểm tra ANTHROPIC_API_KEY.');

    $fails = false;
    $this->travel(15)->minutes();
    app(SyncNoticeFeed::class)->handle(Technology::Php);
    app(SyncNoticeFeed::class)->handle(Technology::Nodejs);

    expect(chatMessagesTo('ADMIN'))->toHaveCount(2)
        ->and(chatJson(chatMessagesTo('ADMIN')->last()))->toContain('AI phân loại tin bảo mật đã hoạt động lại', 'Lỗi từ 23/10/2026 17:00 GMT+7 tới 23/10/2026 17:30 GMT+7.');
});

it('tells the admin again when the classifier fails after it worked again', function () {
    Exceptions::fake();
    fakeGoogleChat();
    $feed = noticeFixture('php-feed.atom');
    Http::fake([PhpNetNoticeFeed::URL => function () use (&$feed) {
        return Http::response($feed);
    }]);
    $fails = true;
    classifyNoticesWith(function () use (&$fails): NoticeClassification {
        return $fails ? throw new RuntimeException('Overloaded') : new NoticeClassification(isSecurityNews: true, severity: Severity::Critical);
    });

    app(SyncNoticeFeed::class)->handle(Technology::Php);
    $fails = false;
    app(SyncNoticeFeed::class)->handle(Technology::Php);
    $fails = true;
    $feed = str_replace('Upcoming Critical PHP Security Release', 'Upcoming Critical PHP Security Release (updated)', $feed);
    $this->travel(15)->minutes();
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(chatMessagesTo('ADMIN')->map(chatJson(...)))
        ->toHaveCount(3)
        ->sequence(
            fn ($message) => $message->toContain('AI phân loại tin bảo mật đang lỗi', 'Lỗi: RuntimeException: Overloaded', 'Kiểm tra log lỗi.'),
            fn ($message) => $message->toContain('AI phân loại tin bảo mật đã hoạt động lại'),
            fn ($message) => $message->toContain('AI phân loại tin bảo mật đang lỗi'),
        );
});

it('keeps a dismissal when the vendor edits the entry and the classifier cannot read the new text', function () {
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

    expect(Advisory::where('title', 'PHPKonf 2025 Baku: security notice')->sole())
        ->is_dismissed->toBeTrue()
        ->classification_failed_at->not->toBeNull();
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

it('classifies every entry it stores, release announcements and the history of the feed included, and stores what the classifier read', function () {
    fakeNoticeFeeds();
    $classified = classifyNoticesWith(fn (NoticeEntry $entry): NoticeClassification => new NoticeClassification(
        isSecurityNews: true,
        severity: Severity::Critical,
        plannedReleaseAt: CarbonImmutable::parse('2026-10-21 14:00:00'),
        affectedBranches: ['8.3', '8.4'],
        summary: 'PHP sẽ vá hai lỗ hổng nghiêm trọng.',
        patchVersions: str_starts_with($entry->title, 'Security advisory') ? ['8.5.12', '8.4.27', '8.3.36', '8.2.35'] : [],
    ));

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect($classified->getArrayCopy())->toEqualCanonicalizing([
        'Security advisory: Critical remote code execution vulnerabilities, update now',
        'PHP 8.3.36 Released!',
        'Upcoming Critical PHP Security Release',
        'PHP 8.6.0 RC3 available for testing',
        'PHP 8.3.35 Released!',
        'Dutch PHP Conference 2027',
    ])->and(Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole())
        ->classified_by->toBe('claude-haiku-5-5')
        ->classified_severity->toBe(Severity::Critical)
        ->planned_release_at->toIso8601String()->toBe('2026-10-21T14:00:00+00:00')
        ->affected_branches->toBe(['8.3', '8.4'])
        ->localized_summary->toBe('PHP sẽ vá hai lỗ hổng nghiêm trọng.')
        ->patch_versions->toBeNull()
        ->is_dismissed->toBeFalse()
        ->and(Advisory::where('title', 'like', 'Security advisory%')->sole()->patch_versions)->toBe(['8.5.12', '8.4.27', '8.3.36', '8.2.35'])
        ->and(Advisory::where('title', 'Dutch PHP Conference 2027')->sole())
        ->classified_by->toBe('claude-haiku-5-5')
        ->is_backfill->toBeTrue();
});

it('neither stores nor reads an entry published before the collection window, never fewer days than the alerts look back', function (int $collectDays, int $relevantDays, array $read) {
    config(['psm.notices.collect_days' => $collectDays, 'psm.notices.relevant_days' => $relevantDays]);
    fakeNoticeFeeds();
    $classified = classifyNoticesWith(fn (): NoticeClassification => new NoticeClassification(isSecurityNews: true, severity: Severity::Critical));

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect($classified->getArrayCopy())->toEqualCanonicalizing($read)
        ->and(Advisory::pluck('title')->all())->toEqualCanonicalizing($read);
})->with([
    '90 days' => [90, 14, ['Security advisory: Critical remote code execution vulnerabilities, update now', 'PHP 8.3.36 Released!', 'Upcoming Critical PHP Security Release', 'PHP 8.6.0 RC3 available for testing', 'PHP 8.3.35 Released!', 'Dutch PHP Conference 2027']],
    '30 days' => [30, 14, ['Security advisory: Critical remote code execution vulnerabilities, update now', 'PHP 8.3.36 Released!', 'Upcoming Critical PHP Security Release', 'PHP 8.6.0 RC3 available for testing', 'PHP 8.3.35 Released!']],
    'shorter than the alerts look back' => [1, 14, ['Security advisory: Critical remote code execution vulnerabilities, update now', 'PHP 8.3.36 Released!', 'Upcoming Critical PHP Security Release']],
]);

it('keeps following the edits of an entry it stored before it left the collection window', function () {
    $feed = noticeFixture('php-feed.atom');
    Http::fake([PhpNetNoticeFeed::URL => function () use (&$feed) {
        return Http::response($feed);
    }]);
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    $this->travel(120)->days();
    $feed = str_replace('Dutch PHP Conference 2027', 'Dutch PHP Conference 2027 (moved)', $feed);
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::count())->toBe(6)
        ->and(Advisory::where('title', 'Dutch PHP Conference 2027 (moved)')->exists())->toBeTrue();
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

    expect($classified)->toHaveCount(6)
        ->and(collect($advisories)->contains(fn (Advisory $advisory): bool => $advisory->wasRecentlyCreated || $advisory->wasChanged()))->toBeFalse();

    $feed = str_replace('scheduled for Wednesday, 21 October 2026 at 14:00 UTC', 'scheduled for Thursday, 22 October 2026 at 14:00 UTC', $feed);
    app(SyncNoticeFeed::class)->handle($php);

    expect($classified)->toHaveCount(7)
        ->and($classified[6])->toBe('Upcoming Critical PHP Security Release');
});

it('rates an entry by its CVSS score before the classifier, higher or lower, and by the classifier without one', function (string $score, Severity $classified, Severity $severity) {
    Http::fake([PhpNetNoticeFeed::URL => Http::response(str_replace('CVSS v4.0 9.2', "CVSS v4.0 {$score}", noticeFixture('php-feed.atom')))]);
    classifyNoticesWith(fn (): NoticeClassification => new NoticeClassification(isSecurityNews: true, severity: $classified));

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole())
        ->severity->toBe($severity)
        ->severity_basis->toBe(SeverityBasis::Cvss)
        ->and(Advisory::where('title', 'Dutch PHP Conference 2027')->sole())
        ->severity->toBe($classified)
        ->severity_basis->toBe(SeverityBasis::Classifier);
})->with([
    'a lower CVSS score' => ['5.3', Severity::Critical, Severity::Medium],
    'a higher CVSS score' => ['9.2', Severity::Low, Severity::Critical],
]);

it('lets the classifier rate an entry, but not lower its CVSS score', function () {
    fakeNoticeFeeds();
    classifyNoticesWith(fn (NoticeEntry $entry): NoticeClassification => new NoticeClassification(
        isSecurityNews: true,
        severity: str_starts_with($entry->title, 'Dutch PHP Conference') ? Severity::Critical : Severity::Low,
    ));

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', 'Security advisory: Critical remote code execution vulnerabilities, update now')->sole())
        ->severity->toBe(Severity::Low)
        ->severity_basis->toBe(SeverityBasis::Classifier)
        ->and(Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole())
        ->severity->toBe(Severity::Critical)
        ->severity_basis->toBe(SeverityBasis::Cvss)
        ->and(Advisory::where('title', 'Dutch PHP Conference 2027')->value('severity'))->toBe(Severity::Critical);
});

it('keeps the severity the classifier gave when the vendor edits the entry and the classifier cannot read the new text', function () {
    Exceptions::fake();
    $feed = noticeFixture('php-feed.atom');
    Http::fake([PhpNetNoticeFeed::URL => function () use (&$feed) {
        return Http::response($feed);
    }]);
    $fails = false;
    classifyNoticesWith(function () use (&$fails): NoticeClassification {
        return $fails ? throw new RuntimeException('Overloaded') : new NoticeClassification(isSecurityNews: true, severity: Severity::Low, patchVersions: ['8.3.36']);
    });
    $title = 'Security advisory: Critical remote code execution vulnerabilities, update now';
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', $title)->value('severity'))->toBe(Severity::Low);

    $fails = true;
    $feed = str_replace('update now</title>', 'update today</title>', $feed);
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', 'Security advisory: Critical remote code execution vulnerabilities, update today')->sole())
        ->severity->toBe(Severity::Low)
        ->severity_basis->toBe(SeverityBasis::Classifier)
        ->patch_versions->toBe(['8.3.36'])
        ->is_dismissed->toBeFalse();
});

it('dismisses an entry the classifier finds is not security news whatever its words, unless its CVSS score says otherwise', function () {
    fakeNoticeFeeds();
    classifyNoticesWith(fn (): NoticeClassification => new NoticeClassification(isSecurityNews: false, severity: Severity::Unknown));

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('is_dismissed', true)->pluck('title')->all())->toEqualCanonicalizing([
        'Security advisory: Critical remote code execution vulnerabilities, update now',
        'PHP 8.3.36 Released!',
        'PHP 8.6.0 RC3 available for testing',
        'PHP 8.3.35 Released!',
        'Dutch PHP Conference 2027',
    ])->and(Advisory::where('title', 'Dutch PHP Conference 2027')->sole())
        ->severity->toBe(Severity::Unknown)
        ->severity_basis->toBeNull();
});

it('leaves the entries the classifier could not read waiting, unless their CVSS score says otherwise, and classifies them on the next poll', function () {
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
        ->is_dismissed->toBeFalse()
        ->and(Advisory::where('is_dismissed', true)->count())->toBe(5)
        ->and(SourceHeartbeat::where('source', 'notice:php')->value('last_error'))->toBeNull();

    $fails = false;
    $this->travel(15)->minutes();
    app(SyncNoticeFeed::class)->handle($php);

    expect($classified)->toHaveCount(12)
        ->and(Advisory::whereNotNull('classified_by')->count())->toBe(6)
        ->and(Advisory::where('is_dismissed', true)->count())->toBe(0);
});

it('does not call the classifier when no API key is configured', function () {
    fakeNoticeFeeds();

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::whereNotNull('classified_by')->count())->toBe(0)
        ->and(Advisory::count())->toBe(6);
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

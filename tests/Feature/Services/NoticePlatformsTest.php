<?php

use App\Actions\SyncNoticeFeed;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Services\Notices\NoticeClassification;
use App\Services\Notices\PhpNetNoticeFeed;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-10-21 00:00:00');
});

it('stores the operating systems the classifier read only when the sentence it quotes is in the entry', function (array $platforms, ?string $quote, ?array $stored) {
    $feed = str_replace('It addresses two critical vulnerabilities', 'Only the Windows builds 8.x.y-1 are affected; PHP runtime versions remain unchanged. It addresses two critical vulnerabilities', noticeFixture('php-feed.atom'));
    Http::fake([PhpNetNoticeFeed::URL => Http::response($feed)]);
    classifyNoticesWith(fn (): NoticeClassification => new NoticeClassification(isSecurityNews: true, severity: Severity::Critical, platforms: $platforms, platformsEvidence: $quote));

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    $notice = Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole();
    expect($notice->platforms)->toBe($stored)
        ->and($notice->platforms_evidence)->toBe($stored === null ? null : $quote)
        ->and($notice->incident->platform_scopes === null)->toBe($stored === null);
})->with([
    'quoted from the entry' => [['windows'], 'Only the Windows builds 8.x.y-1 are affected', ['windows']],
    'quoted with other spacing and case' => [['windows'], 'only the windows   builds 8.x.y-1 are affected', ['windows']],
    'an invented sentence' => [['windows'], 'This vulnerability only affects PHP on Windows servers.', null],
    'no sentence at all' => [['windows'], null, null],
    'no limit' => [[], null, null],
]);

it('drops a limit read before once the vendor removes its sentence from the entry', function () {
    $sentence = 'Only the Windows builds 8.x.y-1 are affected.';
    $feed = str_replace('It addresses two critical vulnerabilities', "{$sentence} It addresses two critical vulnerabilities", noticeFixture('php-feed.atom'));
    Http::fake([PhpNetNoticeFeed::URL => function () use (&$feed) {
        return Http::response($feed);
    }]);
    classifyNoticesWith(fn (): NoticeClassification => new NoticeClassification(isSecurityNews: true, severity: Severity::Critical, platforms: ['windows'], platformsEvidence: $sentence));
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    $this->travel(30)->days();
    $feed = str_replace($sentence, 'Every build is affected.', $feed);
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::where('title', 'Upcoming Critical PHP Security Release')->sole())->platforms->toBeNull()->platforms_evidence->toBeNull();
});

it('reads an unchanged entry again once the classifier\'s revision is newer, only within the collection window', function () {
    Http::fake([PhpNetNoticeFeed::URL => Http::response(noticeFixture('php-feed.atom'))]);
    classifyNoticesWith(fn (): NoticeClassification => new NoticeClassification(isSecurityNews: false, severity: Severity::Unknown));
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect(Advisory::pluck('classifier_revision')->unique()->all())->toBe([1]);

    config(['psm.notices.collect_days' => 30]);
    $titles = classifyNoticesWith(fn (): NoticeClassification => new NoticeClassification(isSecurityNews: false, severity: Severity::Unknown), revision: 2);
    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect($titles->getArrayCopy())->toEqualCanonicalizing([
        'Security advisory: Critical remote code execution vulnerabilities, update now',
        'PHP 8.3.36 Released!',
        'Upcoming Critical PHP Security Release',
        'PHP 8.6.0 RC3 available for testing',
        'PHP 8.3.35 Released!',
    ])
        ->and(Advisory::where('title', 'Dutch PHP Conference 2027')->value('classifier_revision'))->toBe(1);

    app(SyncNoticeFeed::class)->handle(Technology::Php);

    expect($titles)->toHaveCount(5);
});

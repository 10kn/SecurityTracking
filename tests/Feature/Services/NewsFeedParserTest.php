<?php

use App\Services\Notices\NewsFeedParser;
use App\Services\Notices\NoticeEntry;

it('reads the php.net atom feed, including the pre-announcement of 2026-10-20', function () {
    $entries = NewsFeedParser::parse(noticeFixture('php-feed.atom'));

    $preNotice = collect($entries)->firstWhere('externalId', 'https://www.php.net/archive/2026.php#2026-10-20-1');

    expect($entries)->toHaveCount(7)
        ->and($preNotice)
        ->title->toBe('Upcoming Critical PHP Security Release')
        ->url->toBe('https://www.php.net/archive/2026.php#2026-10-20-1')
        ->publishedAt->toIso8601String()->toBe('2026-10-20T20:32:00+00:00')
        ->text->toStartWith('The PHP security team would like to give advance notice of an upcoming security release.')
        ->text->toContain('It is scheduled for Wednesday, 21 October 2026 at 14:00 UTC.', 'both rated CVSS v4.0 9.2')
        ->and($preNotice->cvssScore())->toBe(9.2);
});

it('reads the nodejs.org vulnerability feed, keyed by link because its guids carry the publication time', function () {
    $entry = NewsFeedParser::parse(noticeFixture('nodejs-vulnerability.xml'))[0];

    expect($entry)
        ->externalId->toBe('https://nodejs.org/en/blog/vulnerability/october-2026-security-releases')
        ->title->toBe('Wednesday, October 21, 2026 Security Releases')
        ->text->toBe('')
        ->url->toBe('https://nodejs.org/en/blog/vulnerability/october-2026-security-releases')
        ->publishedAt->toIso8601String()->toBe('2026-10-21T22:00:00+00:00');
});

it('reads the ruby-lang.org news feed as plain text', function () {
    $entries = NewsFeedParser::parse(noticeFixture('ruby-news.rss'));

    expect($entries)->toHaveCount(4)
        ->and($entries[0])
        ->externalId->toBe('https://www.ruby-lang.org/en/news/2026/10/23/ruby-3-3-13-released/')
        ->title->toBe('Ruby 3.3.13 Released')
        ->text->toStartWith('Ruby 3.3.13 has been released. This release includes security fixes.')
        ->text->not->toContain('<', '&lt;', '&quot;')
        ->and($entries[0]->cveIds())->toContain('CVE-2026-80212', 'CVE-2026-110264');
});

it('refuses documents with a doctype, so no entity is loaded or expanded', function () {
    $xml = <<<'XML'
        <?xml version="1.0"?>
        <!DOCTYPE rss [<!ENTITY secret SYSTEM "file:///etc/passwd">]>
        <rss version="2.0"><channel><item><title>&secret;</title><link>https://example.com/a</link></item></channel></rss>
        XML;

    NewsFeedParser::parse($xml);
})->throws(UnexpectedValueException::class);

it('refuses responses that are not a news feed', function (string $body) {
    NewsFeedParser::parse($body);
})->with([
    'empty' => '',
    'html page' => '<html><body>Maintenance</body></html>',
    'broken xml' => '<rss><channel><item>',
])->throws(UnexpectedValueException::class);

it('reads cvss scores but not vector strings', function () {
    expect((new NoticeEntry('id', 'Advisory', 'CVSS:3.1/AV:N/AC:L, CVSS score 7.5 and CVSS 9.8'))->cvssScore())->toBe(9.8)
        ->and((new NoticeEntry('id', 'Advisory', 'Vector CVSS:3.1/AV:N/AC:L'))->cvssScore())->toBeNull();
});

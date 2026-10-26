<?php

use App\Enums\Severity;
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
        ->and($preNotice->severity())->toBe(Severity::Critical)
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

it('takes the severity from the words the vendor uses', function (string $text, Severity $severity) {
    expect((new NoticeEntry('id', 'Security release', $text))->severity())->toBe($severity);
})->with([
    'critical' => ['Fixes two critical vulnerabilities.', Severity::Critical],
    'high' => ['1 high severity issue, 2 medium.', Severity::High],
    'neither' => ['We consider the risk highest for mod_php.', Severity::Unknown],
]);

it('reads cvss scores but not vector strings', function () {
    expect((new NoticeEntry('id', 'Advisory', 'CVSS:3.1/AV:N/AC:L, CVSS score 7.5 and CVSS 9.8'))->cvssScore())->toBe(9.8)
        ->and((new NoticeEntry('id', 'Advisory', 'Vector CVSS:3.1/AV:N/AC:L'))->cvssScore())->toBeNull();
});

it('reads only the versions an entry tells to update to', function (string $text, array $versions) {
    expect((new NoticeEntry('id', 'PHP security advisory', $text))->patchVersions())->toBe($versions);
})->with([
    'php.net advisory' => ['Update to PHP 8.5.12, 8.4.27, 8.3.36 or 8.2.35. Source downloads are on our downloads page.', ['8.5.12', '8.4.27', '8.3.36', '8.2.35']],
    'upgrade wording' => ['Please upgrade to Ruby 3.4.7, 3.3.9 or 3.2.10.', ['3.4.7', '3.3.9', '3.2.10']],
    'fixed in, then other versions' => ['This is fixed in v22.20.1 and v24.9.2; older lines such as 22.20.0 are affected.', ['22.20.1', '24.9.2']],
    'upgrade from one version to another' => ['Upgrade from 8.3.35 to 8.3.36 now.', ['8.3.36']],
    'a condition naming the running version' => ['Update to PHP 8.3.36 if you are running PHP 8.4.26.', ['8.3.36']],
    'negated' => ['This issue is not fixed in PHP 8.3.36.', []],
    'a pre-release' => ['Update to PHP 8.3.36-rc1 for testing.', []],
    'versions outside such a sentence' => ['PHP 8.3.36 fixes two vulnerabilities. Version 8.2.30 is affected.', []],
    'node.js' => ['Update to Node.js 22.20.1 or 24.9.2 as soon as possible.', ['22.20.1', '24.9.2']],
    'a domain after the versions' => ['Please upgrade to Ruby 3.3.6 or see ruby-lang.org for details.', ['3.3.6']],
    'ruby-lang.org advisory' => ['We recommend upgrading to Ruby 4.0.9, 3.4.12 or 3.3.13 or later. If you cannot upgrade, please avoid compiling regular expressions from untrusted input.', ['4.0.9', '3.4.12', '3.3.13']],
    'but not' => ['This is fixed in PHP 8.3.36 but not PHP 8.4.26.', ['8.3.36']],
    'not' => ['Update to PHP 8.3.36, not 8.3.35.', ['8.3.36']],
    'however' => ['Update to PHP 8.3.36, however PHP 8.2.30 remains vulnerable.', ['8.3.36']],
    'although' => ['Patched in Ruby 3.4.7, although 3.3.9 is still being worked on.', ['3.4.7']],
    'though' => ['Fixed in v24.9.2, though v22.20.0 waits for the next release.', ['24.9.2']],
    'whereas' => ['Fixed in v24.9.2 whereas v22.20.0 waits for the next release.', ['24.9.2']],
    'yet' => ['Upgrade to PHP 8.4.27, yet 8.3.35 has no fix.', ['8.4.27']],
    'excluding' => ['Update to Node.js 22.20.1 or 24.9.2, excluding 22.20.2 which has a regression.', ['22.20.1', '24.9.2']],
]);

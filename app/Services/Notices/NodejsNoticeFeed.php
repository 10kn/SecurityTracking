<?php

namespace App\Services\Notices;

use App\Services\Catalog\CatalogHttp;

/**
 * https://nodejs.org/en/feed/vulnerability.xml (RSS) lists only the Node.js vulnerability blog: security release
 * pre-announcements and assessments. Its items have a title and no text, so the text of each entry is read from its
 * post on nodejs.org (see NewsFeedParser::post()), which the project edits with the details once the releases are out.
 *
 * Only the posts of entries published within `psm.notices.collect_days` are read, never fewer days than
 * `psm.notices.relevant_days` (see SyncNoticeFeed), and the older entries are left out: the feed lists years of
 * posts, and a pre-notice stored before keeps the text it read last. A post that cannot be read fails the poll, so
 * the feed is polled again on the next run and its heartbeat tells the admin when it keeps failing, rather than
 * storing the title alone in place of the text.
 */
class NodejsNoticeFeed implements NoticeFeed
{
    public const string URL = 'https://nodejs.org/en/feed/vulnerability.xml';

    /**
     * The posts it reads: those of the Node.js blog. An entry linking elsewhere keeps its title alone.
     */
    public const string POSTS = 'https://nodejs.org/en/blog/';

    public function entries(): array
    {
        $since = now()->subDays(max(config('psm.notices.collect_days'), config('psm.notices.relevant_days')));

        return collect(NewsFeedParser::parse(CatalogHttp::client()->get(self::URL)->body()))
            ->reject(fn (NoticeEntry $entry): bool => $entry->publishedAt?->isBefore($since) ?? false)
            ->map(fn (NoticeEntry $entry): NoticeEntry => $this->withPost($entry))
            ->values()
            ->all();
    }

    private function withPost(NoticeEntry $entry): NoticeEntry
    {
        if ($entry->url === null || ! str_starts_with($entry->url, self::POSTS)) {
            return $entry;
        }

        $post = NewsFeedParser::post(CatalogHttp::client()->get($entry->url)->body(), $entry->url);

        return new NoticeEntry(
            externalId: $entry->externalId,
            title: $entry->title,
            text: $post['text'],
            url: $entry->url,
            publishedAt: $entry->publishedAt,
            links: $post['links'],
        );
    }
}

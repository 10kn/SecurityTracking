<?php

namespace App\Services\Notices;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Reads an Atom or RSS 2.0 feed into plain-text entries. Feeds come from the internet, so the parser never uses the
 * network and refuses documents with a DOCTYPE: no external entities, and no internal ones to expand.
 */
final class NewsFeedParser
{
    private const string ATOM = 'http://www.w3.org/2005/Atom';

    /**
     * @return list<NoticeEntry>
     *
     * @throws UnexpectedValueException When the body is not an Atom or RSS feed.
     */
    public static function parse(string $xml): array
    {
        if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false) {
            throw new UnexpectedValueException('The response is not a news feed.');
        }

        $document = new DOMDocument;
        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            $isLoaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        $root = $document->documentElement;

        if (! $isLoaded || $root === null) {
            throw new UnexpectedValueException('The response is not valid XML.');
        }

        $entries = match (true) {
            $root->namespaceURI === self::ATOM && $root->localName === 'feed' => self::atomEntries($document),
            $root->localName === 'rss' => self::rssEntries($document),
            default => throw new UnexpectedValueException('The response is neither an Atom nor an RSS feed.'),
        };

        return array_values(array_filter($entries, fn (NoticeEntry $entry): bool => $entry->externalId !== '' && $entry->title !== ''));
    }

    /**
     * The entry id is the stable key. php.net's ids are archive permalinks, while its "alternate" links point at the
     * front page, which drops old news, so an id that is a web address is also the link.
     *
     * @return list<NoticeEntry>
     */
    private static function atomEntries(DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('a', self::ATOM);

        $entries = [];

        foreach ($xpath->query('/a:feed/a:entry') ?: [] as $entry) {
            $id = trim($xpath->evaluate('string(a:id)', $entry));
            $link = trim($xpath->evaluate('string(a:link[@rel="alternate" or not(@rel)][1]/@href)', $entry));
            $contents = $xpath->query('a:content | a:summary', $entry);
            $content = $contents === false ? null : $contents->item(0);
            $url = self::isWebAddress($id) ? $id : (self::isWebAddress($link) ? $link : null);

            $entries[] = new NoticeEntry(
                externalId: $id !== '' ? $id : $link,
                title: Str::squish($xpath->evaluate('string(a:title)', $entry)),
                text: $content instanceof DOMElement ? self::atomText($content) : '',
                url: $url,
                publishedAt: self::date($xpath->evaluate('string(a:published)', $entry) ?: $xpath->evaluate('string(a:updated)', $entry)),
                links: $content instanceof DOMElement ? self::links(self::atomHrefs($xpath, $content), $url) : [],
            );
        }

        return $entries;
    }

    /**
     * The link is the stable key: nodejs.org puts the publication time in its guids.
     *
     * @return list<NoticeEntry>
     */
    private static function rssEntries(DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);

        $entries = [];

        foreach ($xpath->query('/rss/channel/item') ?: [] as $item) {
            $link = trim($xpath->evaluate('string(link)', $item));
            $guid = trim($xpath->evaluate('string(guid)', $item));
            $description = $xpath->evaluate('string(description)', $item);
            $url = self::isWebAddress($link) ? $link : (self::isWebAddress($guid) ? $guid : null);

            $entries[] = new NoticeEntry(
                externalId: $link !== '' ? $link : $guid,
                title: Str::squish($xpath->evaluate('string(title)', $item)),
                text: self::htmlToText($description),
                url: $url,
                publishedAt: self::date($xpath->evaluate('string(pubDate)', $item)),
                links: self::links(self::htmlHrefs($description), $url),
            );
        }

        return $entries;
    }

    /**
     * The links of Atom content: the href attributes of XHTML content, or those written in escaped HTML content.
     *
     * @return list<string>
     */
    private static function atomHrefs(DOMXPath $xpath, DOMElement $content): array
    {
        if ($content->getAttribute('type') !== 'xhtml') {
            return self::htmlHrefs($content->textContent);
        }

        return array_map(fn (DOMNode $href): string => (string) $href->nodeValue, iterator_to_array($xpath->query('.//*[local-name()="a"]/@href', $content) ?: []));
    }

    /**
     * @return list<string>
     */
    private static function htmlHrefs(string $html): array
    {
        preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $matches);

        return $matches[2];
    }

    /**
     * Absolute, normalized and without duplicates, at most 100.
     *
     * @param  list<string>  $hrefs
     * @return list<string>
     */
    private static function links(array $hrefs, ?string $base): array
    {
        return collect($hrefs)
            ->map(fn (string $href): ?string => NoticeUrl::resolve($href, $base))
            ->filter()
            ->unique()
            ->take(100)
            ->values()
            ->all();
    }

    private static function atomText(DOMElement $content): string
    {
        return match ($content->getAttribute('type')) {
            'html' => self::htmlToText($content->textContent),
            'xhtml' => self::htmlToText(implode('', array_map(
                fn (DOMNode $node): string => (string) $content->ownerDocument?->saveXML($node),
                iterator_to_array($content->childNodes),
            ))),
            default => Str::squish($content->textContent),
        };
    }

    /**
     * Block tags become spaces, so "<p>a</p><p>b</p>" reads "a b" while "<b>a</b>." stays "a.".
     */
    private static function htmlToText(string $html): string
    {
        $text = preg_replace('#</?(?:p|div|br|li|ul|ol|h[1-6]|pre|blockquote|table|tr|td|th)\b[^>]*>#i', ' ', $html) ?? $html;

        return Str::squish(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function isWebAddress(string $value): bool
    {
        return preg_match('#^https?://#i', $value) === 1;
    }

    private static function date(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}

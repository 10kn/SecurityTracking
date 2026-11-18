<?php

namespace App\Services\Notices;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnexpectedValueException;

/** Reads the official Japanese alert archive and CyberNewsFlash index without rendering the pages. */
class JpcertNewsFeed
{
    public const string ORIGIN = 'https://www.jpcert.or.jp';

    public const string ALERTS = 'at';

    public const string FLASH = 'newsflash';

    public const string TIMEZONE = 'Asia/Tokyo';

    /** @return list<array{url: string, title: string, listed_on: string}> */
    public function entries(string $section, CarbonImmutable $since, CarbonImmutable $today): array
    {
        $paths = $section === self::ALERTS
            ? array_map(fn (int $year): string => "/at/{$year}.html", range($since->year, $today->year))
            : ['/newsflash/'];

        $entries = [];
        foreach ($paths as $path) {
            $index = self::ORIGIN.$path;
            $xpath = $this->document($index);
            if (! $xpath->query('//article//table')->length) {
                throw new UnexpectedValueException('The JPCERT/CC index has no article table.');
            }

            $datedRows = 0;
            $recognizedRows = 0;
            $rows = $xpath->query('//article//table//tr[td]');
            foreach ($rows as $row) {
                $date = trim($xpath->evaluate('string(td[1])', $row));
                if (! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)) {
                    continue;
                }

                $datedRows++;
                foreach ($xpath->query('./td[position() > 1]//a[@href]', $row) as $link) {
                    $url = $this->articleUrl($section, $xpath->evaluate('string(@href)', $link), $index);
                    $title = Str::squish($link->textContent);
                    if ($url === null || $title === '') {
                        continue;
                    }

                    $recognizedRows++;
                    if ($date >= $since->toDateString() && $date <= $today->toDateString() && $date > ($entries[$url]['listed_on'] ?? '')) {
                        $entries[$url] = ['url' => $url, 'title' => $title, 'listed_on' => $date];
                    }
                    break;
                }
            }

            $emptyNewYear = $section === self::ALERTS && count($paths) > 1
                && $path === "/at/{$today->year}.html" && $rows->length === 0;
            if ($recognizedRows === 0 && ! $emptyNewYear) {
                $reason = $datedRows === 0 ? 'no recognizable article dates' : 'no recognizable article links';

                throw new UnexpectedValueException("The JPCERT/CC index has {$reason}.");
            }
        }

        return array_values($entries);
    }

    public function article(string $section, string $url, string $listingTitle, CarbonImmutable $listedOn): NoticeEntry
    {
        if ($this->articleUrl($section, $url) !== $url) {
            throw new UnexpectedValueException('Unexpected JPCERT/CC article URL.');
        }

        $xpath = $this->document($url);
        $article = $xpath->query('//article')->item(0);
        if (! $article instanceof DOMElement) {
            throw new UnexpectedValueException('The JPCERT/CC page has no article content.');
        }

        $title = Str::squish($xpath->evaluate('string((//div[contains(concat(" ", normalize-space(@class), " "), " page_title_area ")]/h3)[1])')) ?: $listingTitle;
        $text = $this->articleText($xpath, $article);
        if ($text === '') {
            throw new UnexpectedValueException('The JPCERT/CC article has no text.');
        }

        $publishedLabel = $section === self::ALERTS ? '/\b(\d{4}-\d{2}-\d{2})\s*[（(]\s*公開/u' : '/\b(\d{4}-\d{2}-\d{2})\s*初版/u';
        $publishedOn = preg_match($publishedLabel, $text, $match)
            ? CarbonImmutable::parse($match[1], self::TIMEZONE)
            : $listedOn;

        return new NoticeEntry(
            externalId: $url,
            title: $title,
            text: Str::limit($text, NewsFeedParser::POST_LENGTH),
            url: $url,
            publishedAt: $publishedOn,
        );
    }

    private function articleUrl(string $section, string $href, string $base = self::ORIGIN): ?string
    {
        try {
            $url = UriResolver::resolve(new Uri($base), new Uri(trim($href)));
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($url->getHost() !== 'www.jpcert.or.jp' || ! in_array($url->getScheme(), ['http', 'https'], true)
            || $url->getUserInfo() !== '' || $url->getPort() !== null) {
            return null;
        }

        $pattern = $section === self::ALERTS
            ? '#\A/at/\d{4}/at\d+\.html\z#'
            : '#\A/newsflash/\d{10}\.html\z#';

        return preg_match($pattern, $url->getPath()) ? self::ORIGIN.$url->getPath() : null;
    }

    private function document(string $url): DOMXPath
    {
        $response = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()->get($url);
        if (! $response->successful()) {
            throw new UnexpectedValueException("JPCERT/CC returned HTTP {$response->status()} for {$url}.");
        }

        $document = new DOMDocument;
        if (! $document->loadHTML('<?xml encoding="UTF-8">'.$response->body(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new UnexpectedValueException('Invalid JPCERT/CC HTML.');
        }

        return new DOMXPath($document);
    }

    private function articleText(DOMXPath $xpath, DOMElement $article): string
    {
        foreach ($xpath->query('.//script | .//style', $article) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $html = $article->ownerDocument->saveHTML($article);
        $spaced = preg_replace('#</(?:p|div|h[1-6]|li|dt|dd|td|th|pre|blockquote)>|<br\s*/?>#i', ' ', (string) $html);

        return Str::squish(html_entity_decode(strip_tags((string) $spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}

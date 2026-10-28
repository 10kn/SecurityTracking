<?php

namespace App\Services\Notices;

use App\Enums\Severity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One feed entry as plain text. `externalId` is the entry's id, link or guid, whichever the feed keeps stable.
 * `links` are the absolute addresses the entry links to, normalized (see NoticeUrl).
 */
final readonly class NoticeEntry
{
    /**
     * Phrases that say a vulnerability is being exploited, as opposed to exploitable.
     */
    private const string EXPLOITED = '/\b(?:(?:actively|being|been)\s+exploit(?:ed|ing)|exploited\s+in\s+the\s+wild|exploitation\s+in\s+the\s+wild|known\s+to\s+(?:be|have\s+been)\s+exploited|under\s+(?:active\s+)?exploitation|(?:evidence\s+of\s+)?(?:active|ongoing)\s+exploitation)\b/i';

    /**
     * Words that make the whole sentence a condition, e.g. "should evidence of active exploitation appear".
     */
    private const string CONDITION = '/\b(?:if|unless|whether|in\s+case|in\s+the\s+event)\b/i';

    /**
     * Words that deny the clause, and proofs of concept, which are no exploitation.
     */
    private const string DENIAL = '/\b(?:no|not|never|nor|none|neither|without|unaware|yet\s+to|proof[\s-]+of[\s-]+concept|poc)\b|n\'t\b/i';

    /**
     * Words that only suppose the clause, e.g. "could be actively exploited".
     */
    private const string SUPPOSITION = '/\b(?:should|could|would|might|may|can|potential(?:ly)?|possibl[ey]|theoretical(?:ly)?|hypothetical(?:ly)?|likely)\b/i';

    /**
     * Words that tell a predicate is about the exploitation itself, e.g. "there is no evidence they are using this
     * flaw", as opposed to "no patch is available".
     */
    private const string ABOUT_EXPLOITATION = '/\b(?:exploit\w*|attack\w*|target\w*|(?:using|used|use|with|via|through)\s+(?:(?:this|the|these|that|those|its)\s+)?(?:flaw|vulnerabilit(?:y|ies)|bug|issue)s?|evidence|observed|seen|confirmed|reported|in\s+the\s+wild)\b/i';

    /**
     * Words that tell a predicate is about the remedy, e.g. "no confirmed patch is available": never about the
     * exploitation, whatever else it mentions.
     */
    private const string ABOUT_REMEDY = '/\b(?:patch\w*|fix\w*|update\w*|upgrad\w*|releas\w*|workaround\w*|mitigat\w*)\b/i';

    /**
     * Where sentences end: ". ", "! ", "? ", "; " and ": ".
     */
    private const string SENTENCE_END = '/[.!?;:](?=\s|$)/';

    /**
     * Where clauses end: sentence ends, commas and "but", "however" and the like. "And", "or" and "nor" join predicates
     * of one clause, so a denial before the phrase covers them all: "not currently being targeted or actively
     * exploited".
     */
    private const string CLAUSE_END = '/[.!?;:](?=\s|$)|,|\b(?:but|however|although|though|whereas|while)\b/i';

    /**
     * Where the predicate of a phrase ends, looking forward: a clause end, or "and", "or", "nor" starting the next
     * predicate, so "actively exploited and no patch is available" is not denied.
     */
    private const string PREDICATE_END = '/[.!?;:](?=\s|$)|,|\b(?:but|however|although|though|whereas|while|and|or|nor)\b/i';

    /**
     * How a vendor rates a vulnerability in words, `%s` being the level: "two critical vulnerabilities", "Upcoming
     * Critical PHP Security Release", "a high-severity flaw", "Severity: High". The level said of anything else is no
     * rating: "high-quality sessions", "mission-critical systems", "high performance".
     */
    private const array RATINGS = [
        '/(?<![\w-])%s(?:-(?:severity|risk|impact|priority)\b|(?:\s+\w+){0,2}?\s+(?:severity|security|vulnerab\w*|issues?|flaws?|bugs?|risks?|impact|advisor(?:y|ies)|cve-\d+|exploit\w*|remote\s+code|rce)\b)/i',
        '/\b(?:severity|rated|rating|priority|impact)\b[\s:=]*(?:(?:level|of|is|as|a|an)\s+){0,2}%s\b(?!-)/i',
    ];

    /**
     * @param  list<string>  $links
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public string $text = '',
        public ?string $url = null,
        public ?CarbonImmutable $publishedAt = null,
        public array $links = [],
    ) {}

    /**
     * @param  list<string>  $keywords  Matched anywhere in the title or text, ignoring case.
     */
    public function mentionsAny(array $keywords): bool
    {
        return $keywords !== [] && Str::contains("{$this->title} {$this->text}", $keywords, ignoreCase: true);
    }

    /**
     * Critical when the vendor rates a vulnerability critical, High when it rates one high (see RATINGS), otherwise
     * unknown. Only a guess from the words: the classifier, once it has read the entry, replaces it (see SyncNoticeFeed).
     */
    public function severity(): Severity
    {
        return match (true) {
            $this->rates('critical') => Severity::Critical,
            $this->rates('high') => Severity::High,
            default => Severity::Unknown,
        };
    }

    private function rates(string $level): bool
    {
        $text = "{$this->title} {$this->text}";

        foreach (self::RATINGS as $pattern) {
            if (preg_match(sprintf($pattern, $level), $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The highest CVSS score the text gives, e.g. 9.2 from "both rated CVSS v4.0 9.2". Vector strings such as
     * "CVSS:3.1/AV:N" are not scores.
     */
    public function cvssScore(): ?float
    {
        preg_match_all('/\bCVSS(?:\s*v?\d\.\d)?(?:\s+(?:base\s+)?score)?(?:\s+of)?[:\s]+(\d{1,2}(?:\.\d)?)(?!\d|\.\d|\/)/i', "{$this->title} {$this->text}", $matches);

        $scores = array_filter(array_map('floatval', $matches[1]), fn (float $score): bool => $score >= 0 && $score <= 10);

        return $scores === [] ? null : max($scores);
    }

    /**
     * CVE ids the entry mentions, e.g. ["CVE-2026-109618"].
     *
     * @return list<string>
     */
    public function cveIds(): array
    {
        preg_match_all('/\bCVE-\d{4}-\d{4,}\b/i', "{$this->title} {$this->text}", $matches);

        return array_values(array_unique(array_map('strtoupper', $matches[0])));
    }

    /**
     * GitHub security advisory ids the entry mentions or links to, e.g. ["GHSA-w8hw-w9fx-j79w"].
     *
     * @return list<string>
     */
    public function ghsaIds(): array
    {
        preg_match_all('/\bGHSA(?:-[23456789cfghjmpqrvwx]{4}){3}\b/i', implode(' ', [$this->title, $this->text, ...$this->links]), $matches);

        return array_values(array_unique(array_map(fn (string $id): string => 'GHSA-'.strtolower(substr($id, 5)), $matches[0])));
    }

    /**
     * The CVE and GHSA ids of the entry: the ids that tie it to other news of the same issue.
     *
     * @return list<string>
     */
    public function referenceIds(): array
    {
        return [...$this->cveIds(), ...$this->ghsaIds()];
    }

    private function plainText(): string
    {
        return Str::squish("{$this->title}. {$this->text}");
    }

    /**
     * Whether a span of the text is affirmed. The scope is directional: before the span, no condition in its sentence
     * and no condition, denial or supposition in its clause, across coordinated predicates ("neither disclosed nor
     * actively exploited"); after it, none up to the end of its own predicate ("active exploitation has not been
     * observed", "… is not confirmed"), which stops at "and", "or" and "nor" ("actively exploited and no patch is
     * available").
     */
    private static function isAffirmed(string $text, int $start, int $end): bool
    {
        $clauseStart = self::lastBoundary($text, $start, self::CLAUSE_END);
        $predicateEnd = preg_match(self::PREDICATE_END, $text, $match, PREG_OFFSET_CAPTURE, $end) === 1 ? $match[0][1] : strlen($text);

        return ! self::isConditional($text, $start)
            && self::isPlain(substr($text, $clauseStart, $end - $clauseStart))
            && self::isPlain(substr($text, $end, $predicateEnd - $end));
    }

    /**
     * No condition, denial or supposition in the words.
     */
    private static function isPlain(string $words): bool
    {
        return preg_match(self::CONDITION, $words) !== 1 && preg_match(self::DENIAL, $words) !== 1 && preg_match(self::SUPPOSITION, $words) !== 1;
    }

    /**
     * Whether the sentence before `$offset` sets a condition, e.g. "If details become public and …".
     */
    private static function isConditional(string $text, int $offset): bool
    {
        $sentenceStart = self::lastBoundary($text, $offset, self::SENTENCE_END);

        return preg_match(self::CONDITION, substr($text, $sentenceStart, $offset - $sentenceStart)) === 1;
    }

    /**
     * The offset right after the last boundary before `$offset`, or 0.
     */
    private static function lastBoundary(string $text, int $offset, string $pattern): int
    {
        preg_match_all($pattern, substr($text, 0, $offset), $matches, PREG_OFFSET_CAPTURE);

        $last = end($matches[0]);

        return $last === false ? 0 : $last[1] + strlen($last[0]);
    }

    private static function sentenceAround(string $text, int $offset): string
    {
        $start = self::lastBoundary($text, $offset, self::SENTENCE_END);

        return trim(preg_match('/^.*?(?:[.!?](?=\s|$)|$)/s', substr($text, $start), $sentence) === 1 ? $sentence[0] : substr($text, $start));
    }

    /**
     * The versions the entry tells to update to, e.g. ["8.5.12", "8.4.27", "8.3.36", "8.2.35"] from "Update to PHP
     * 8.5.12, 8.4.27, 8.3.36 or 8.2.35." Only versions right after "update/upgrade … to", "fixed in" or "patched in"
     * count, up to the end of the sentence (a dot followed by a space or the end of the text, so "Node.js 22.20.1" and
     * "ruby-lang.org" do not end it), a clause naming other versions ("if you run …", "for …", "from …") or one
     * setting versions apart ("but not …", "however …", "although …", "excluding …"): "fixed in PHP 8.3.36 but not PHP
     * 8.4.26" gives only 8.3.36. A negated phrase ("not fixed in") and pre-releases ("8.3.36-rc1") do not count, nor do
     * other versions in the text.
     *
     * @return list<string>
     */
    public function patchVersions(): array
    {
        $text = "{$this->title}. {$this->text}";

        preg_match_all('/\b(?:(?:update|upgrade|updating|upgrading)(?:\s+[\w.-]+){0,3}?\s+to|(?:fixed|patched)\s+in)\b((?:[^.;:!?]|\.(?!\s|$)){0,200})/i', $text, $sentences, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return collect($sentences)
            ->reject(fn (array $match): bool => preg_match('/\b(?:not|never|no longer|cannot|won\'t|isn\'t|aren\'t)\s+(?:\w+\s+){0,2}$/i', substr($text, max(0, $match[0][1] - 30), min(30, $match[0][1]))) === 1)
            ->map(fn (array $match): string => preg_split('/\b(?:if|when|while|unless|for|from|on|running|affected|except|excluding|but|not|however|although|though|whereas|yet)\b/i', $match[1][0])[0])
            ->flatMap(fn (string $sentence): array => preg_match_all('/(?<![\d.])v?(\d+\.\d+\.\d+)(?![\d.]*\d|-|\w)/i', $sentence, $versions) > 0 ? $versions[1] : [])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The sentence of the entry that says its vulnerability is exploited, e.g. "CVE-2026-1001 is being actively
     * exploited in the wild.", or null when none does. A phrase in a condition ("should evidence of active
     * exploitation appear", "if … exploited"), a denial ("no evidence of active exploitation") or a supposition ("could
     * be actively exploited") does not count, nor does a proof of concept.
     */
    public function exploitationEvidence(): ?string
    {
        $text = $this->plainText();

        preg_match_all(self::EXPLOITED, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$phrase, $offset]) {
            if (self::isAffirmed($text, $offset, $offset + strlen($phrase))) {
                return Str::limit(self::sentenceAround($text, $offset), 300);
            }
        }

        return null;
    }

    /**
     * Whether a sentence the classifier quoted as evidence of exploitation is in the entry, ignoring case and spacing,
     * and is not denied, set as a condition or only supposed there: no keyword is needed ("Attackers are compromising
     * servers using this flaw, and administrators should update immediately."). Its first predicate makes the claim and
     * is held to the same directional scope as a keyword (see isAffirmed()); a later predicate it covers, after a clause
     * end or "and", "or", "nor", may give advice or deny something else, such as the remedy ("… and no confirmed patch
     * is available"), but may not
     * deny the exploitation or set a condition on it ("…, but not with this flaw", "… and there is no evidence they
     * are using this flaw"). Quoted out of "should evidence of active exploitation appear", it does not count.
     */
    public function confirmsExploitation(string $excerpt): bool
    {
        $excerpt = Str::squish($excerpt);

        if (mb_strlen($excerpt) < 12) {
            return false;
        }

        $text = $this->plainText();
        $offset = stripos($text, $excerpt);

        if ($offset === false || self::isConditional($text, $offset)) {
            return false;
        }

        $end = $offset + strlen($excerpt);
        $claimEnd = preg_match(self::PREDICATE_END, $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1 ? min($match[0][1], $end) : $end;
        $later = preg_split(self::PREDICATE_END, substr($text, $claimEnd, $end - $claimEnd)) ?: [];

        return self::isAffirmed($text, $offset, $claimEnd)
            && collect($later)->every(fn (string $predicate): bool => preg_match(self::ABOUT_REMEDY, $predicate) === 1 || preg_match(self::ABOUT_EXPLOITATION, $predicate) !== 1 || (preg_match(self::CONDITION, $predicate) !== 1 && preg_match(self::DENIAL, $predicate) !== 1));
    }

    public function summary(int $length): ?string
    {
        return $this->text === '' ? null : Str::limit($this->text, $length);
    }
}

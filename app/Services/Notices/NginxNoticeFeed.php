<?php

namespace App\Services\Notices;

use App\Enums\Severity;
use App\Services\Catalog\CatalogHttp;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use UnexpectedValueException;

/** The NGINX security advisory page's vendor-maintained source and its dated release changelog. */
class NginxNoticeFeed implements NoticeFeed
{
    public const string URL = 'https://raw.githubusercontent.com/nginx/nginx.org/master/xml/en/security_advisories.xml';

    public const string CHANGELOG = 'https://nginx.org/en/CHANGES';

    public function entries(): array
    {
        $xml = CatalogHttp::client()->get(self::URL)->body();
        $xml = str_replace('<!DOCTYPE article SYSTEM "../../dtd/article.dtd">', '', $xml);
        $document = new DOMDocument;

        if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false
            || ! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new UnexpectedValueException('The NGINX advisory index is not valid XML.');
        }

        $changelog = CatalogHttp::client()->get(self::CHANGELOG)->body();
        if (! preg_match_all('/^Changes with nginx (\d+\.\d+\.\d+)\h+(\d{1,2}\h+[A-Z][a-z]{2}\h+\d{4})\h*\r?$/m', $changelog, $matches)) {
            throw new UnexpectedValueException('The NGINX changelog does not list dated releases.');
        }
        $dates = array_combine($matches[1], $matches[2]);

        $entries = [];
        $xpath = new DOMXPath($document);

        foreach ($xpath->query('/article/section/security/item') ?: [] as $item) {
            if (! $item instanceof DOMElement || preg_match('/^(?:CVE-)?(\d{4}-\d{4,})$/D', $item->getAttribute('cve'), $id) !== 1) {
                continue;
            }

            $cve = 'CVE-'.$id[1];
            $label = trim($item->getAttribute('severity'));
            $good = $item->getAttribute('good');
            $vulnerable = $item->getAttribute('vulnerable');
            preg_match_all('/(?:^|,\s*)(\d+\.\d+\.\d+)\+(?=,|$)/', $good, $fixes);
            $patchVersions = array_values(array_unique($fixes[1]));
            $scope = $this->versionScope($vulnerable, $patchVersions);
            $publishedAt = collect($patchVersions)
                ->map(fn (string $version): ?CarbonImmutable => isset($dates[$version]) ? CarbonImmutable::parse($dates[$version]) : null)
                ->filter()
                ->min();

            $entries[] = new NoticeEntry(
                externalId: $cve,
                title: $item->getAttribute('name'),
                text: "{$cve}. NGINX advisory severity: {$label}. Affected NGINX versions: {$vulnerable}. Vendor lists as not vulnerable: {$good}.",
                url: NoticeUrl::normalize($item->getAttribute('advisory')) ?? 'https://www.cve.org/CVERecord?id='.$cve,
                publishedAt: $publishedAt,
                vendorCveId: $cve,
                vendorSeverity: match (strtolower($label)) {
                    'critical' => Severity::Critical,
                    'major', 'high' => Severity::High,
                    'medium' => Severity::Medium,
                    'minor', 'low' => Severity::Low,
                    default => null,
                },
                vendorPatchVersions: $patchVersions,
                vendorAdvisory: [
                    'label' => $label,
                    'vulnerable' => $vulnerable,
                    'good' => $good,
                    'scope' => $scope,
                    'branches' => $scope === null || $vulnerable === 'all' ? [] : VendorVersionScope::affectedBranches($scope, array_keys($dates)),
                ],
            );
        }

        if ($entries === []) {
            throw new UnexpectedValueException('The NGINX advisory index has no CVE entries.');
        }

        return $entries;
    }

    /**
     * Only unqualified upstream spans are decisive. Preserve other spellings (e.g. nginx/Windows) verbatim.
     *
     * @param  list<string>  $fixes
     * @return array{ranges: list<array{from: ?string, through: ?string}>, fixes: list<string>}|null
     */
    private function versionScope(string $vulnerable, array $fixes): ?array
    {
        if ($vulnerable === 'all') {
            return ['ranges' => [['from' => null, 'through' => null]], 'fixes' => $fixes];
        }

        $ranges = [];

        foreach (explode(',', $vulnerable) as $span) {
            if (preg_match('/^(\d+\.\d+\.\d+)(?:\s*-\s*(\d+\.\d+\.\d+))?$/D', trim($span), $match) !== 1) {
                return null;
            }

            $ranges[] = ['from' => $match[1], 'through' => $match[2] ?? $match[1]];
        }

        return ['ranges' => $ranges, 'fixes' => $fixes];
    }
}

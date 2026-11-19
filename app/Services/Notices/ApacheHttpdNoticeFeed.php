<?php

namespace App\Services\Notices;

use App\Enums\Severity;
use App\Services\Catalog\CatalogHttp;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

/** Apache publishes both legacy CVE 4 records and CVE 5 records in the same JSON file. */
class ApacheHttpdNoticeFeed implements NoticeFeed
{
    public const string URL = 'https://httpd.apache.org/security/vulnerabilities-httpd.json';

    public function entries(): array
    {
        $records = CatalogHttp::client()->get(self::URL)->json();

        if (! is_array($records) || ! array_is_list($records) || $records === []) {
            throw new UnexpectedValueException('The Apache HTTP Server advisory index is not a list of CVE records.');
        }

        $entries = [];
        foreach ($records as $record) {
            $cve = $record['cveMetadata']['cveId'] ?? $record['CVE_data_meta']['ID'] ?? '';
            $state = $record['cveMetadata']['state'] ?? $record['CVE_data_meta']['STATE'] ?? '';
            $cna = $record['containers']['cna'] ?? [];

            if (! in_array($state, ['PUBLISHED', 'PUBLIC'], true) || preg_match('/^CVE-\d{4}-\d{4,}$/D', $cve) !== 1) {
                continue;
            }

            $title = $cna['title'] ?? $record['CVE_data_meta']['TITLE'] ?? null;
            $descriptions = $cna['descriptions'] ?? $record['description']['description_data'] ?? [];
            $description = array_find($descriptions, fn (array $value): bool => in_array($value['lang'] ?? '', ['en', 'eng'], true));
            $severity = $cna['metrics'][0]['other']['content']['text'] ?? $record['impact'][0]['other'] ?? '';
            $timeline = $cna['timeline'] ?? $record['timeline'] ?? [];
            $dates = [];
            foreach ($timeline as $event) {
                if (isset($event['time']) && preg_match('/^(\d+\.\d+\.\d+) released$/iD', $event['value'] ?? '', $release) === 1) {
                    $dates[$release[1]] = $event['time'];
                }
            }

            $date = $record['cveMetadata']['datePublished'] ?? $record['CVE_data_meta']['DATE_PUBLIC'] ?? ($dates === [] ? null : min($dates));

            $entries[] = new NoticeEntry(
                externalId: $cve,
                title: filled($title) ? $title : $cve,
                text: $description['value'] ?? '',
                url: 'https://www.cve.org/CVERecord?id='.$cve,
                publishedAt: $date === null ? null : CarbonImmutable::parse($date),
                vendorCveId: $cve,
                vendorSeverity: match (strtolower($severity)) {
                    'critical' => Severity::Critical,
                    'important', 'high' => Severity::High,
                    'moderate', 'medium' => Severity::Medium,
                    'low' => Severity::Low,
                    default => null,
                },
                vendorPatchVersions: array_keys($dates),
            );
        }

        if ($entries === []) {
            throw new UnexpectedValueException('The Apache HTTP Server advisory index has no public CVE records.');
        }

        return $entries;
    }
}

<?php

namespace App\Enums;

/**
 * What gave a pre-notice its severity: the vendor's own words, the CVSS score the entry gives, the classifier, or CISA
 * KEV listing one of its CVE ids.
 */
enum SeverityBasis: string
{
    case Vendor = 'vendor';
    case Cvss = 'cvss';
    case Classifier = 'classifier';
    case Kev = 'kev';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vendor => __('Mức theo hãng'),
            self::Cvss => __('Mức theo điểm CVSS'),
            self::Classifier => __('Mức do AI đánh giá'),
            self::Kev => __('Mức theo CISA KEV'),
        };
    }
}

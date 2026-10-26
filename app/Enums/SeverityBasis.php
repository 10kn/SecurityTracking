<?php

namespace App\Enums;

/**
 * What gave a pre-notice its severity: the vendor's own words, the CVSS score the entry gives, or the classifier.
 */
enum SeverityBasis: string
{
    case Vendor = 'vendor';
    case Cvss = 'cvss';
    case Classifier = 'classifier';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vendor => __('Mức theo hãng'),
            self::Cvss => __('Mức theo điểm CVSS'),
            self::Classifier => __('Mức theo AI phân loại'),
        };
    }
}

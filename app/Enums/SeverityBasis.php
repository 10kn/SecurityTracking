<?php

namespace App\Enums;

/**
 * What gave a pre-notice its severity: the CVSS score the entry gives, the classifier, or CISA KEV listing one of its
 * CVE ids. `Vendor`, the vendor's words read by keywords, is no longer given: it stays for pre-notices rated before.
 */
enum SeverityBasis: string
{
    case Vendor = 'vendor';
    case Cvss = 'cvss';
    case Classifier = 'classifier';
    case Kev = 'kev';

    /**
     * The vendor's rating is named after the technology's official site the pre-notice came from, when it is known.
     */
    public function getLabel(?Technology $technology = null): string
    {
        return match ($this) {
            self::Vendor => $technology === null ? __('Mức theo trang chính thức') : __('Mức theo trang chính thức :technology', ['technology' => $technology->getLabel()]),
            self::Cvss => __('Mức theo điểm CVSS'),
            self::Classifier => __('Mức do AI đánh giá'),
            self::Kev => __('Mức theo CISA KEV'),
        };
    }
}

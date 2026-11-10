<?php

namespace App\Enums;

/**
 * What gave a pre-notice its severity: CISA KEV listing one of its CVE ids, the security advisories the vendor published
 * on GitHub for the versions it updates to (see VendorSecurityAdvisories), the CVSS score the entry gives, or the
 * classifier. `Vendor`, the vendor's words read by keywords, is no longer given: it stays for pre-notices rated before.
 */
enum SeverityBasis: string
{
    case Vendor = 'vendor';
    case Cvss = 'cvss';
    case Classifier = 'classifier';
    case Kev = 'kev';
    case VendorAdvisory = 'vendor_advisory';

    /**
     * Which basis gives the severity when several rate the same pre-notice, the highest first: CISA KEV, then the
     * vendor's GitHub advisories, then the CVSS score the entry gives, then the classifier, whatever severity each
     * gives (see SyncNoticeFeed).
     */
    public function precedence(): int
    {
        return match ($this) {
            self::Kev => 4,
            self::VendorAdvisory => 3,
            self::Cvss => 2,
            self::Classifier => 1,
            self::Vendor => 0,
        };
    }

    /**
     * The basis alone, shown beside the severity it gave, e.g. "Critical · CISA KEV".
     */
    public function getSourceLabel(?Technology $technology = null): string
    {
        return match ($this) {
            self::Vendor => $technology === null ? __('Trang chính thức') : __('Trang chính thức :technology', ['technology' => $technology->getLabel()]),
            self::Cvss => __('Điểm CVSS'),
            self::Classifier => __('Phân loại bởi AI'),
            self::Kev => 'CISA KEV',
            self::VendorAdvisory => __('GitHub advisory của hãng'),
        };
    }

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
            self::VendorAdvisory => __('Mức theo GitHub advisory của hãng'),
        };
    }
}

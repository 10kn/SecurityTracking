<?php

namespace App\Enums;

/**
 * Why a pre-notice belongs to its incident: it started the incident, it shares a CVE or GHSA id with a pre-notice of
 * the incident, one links to the other, or an admin put it there.
 */
enum IncidentBasis: string
{
    case Own = 'own';
    case SharedId = 'shared_id';
    case DirectLink = 'direct_link';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Own => __('Tin đầu tiên của sự kiện'),
            self::SharedId => __('Chung mã CVE/GHSA'),
            self::DirectLink => __('Liên kết trực tiếp'),
            self::Manual => __('Admin gộp tay'),
        };
    }
}

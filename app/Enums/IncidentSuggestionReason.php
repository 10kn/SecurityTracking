<?php

namespace App\Enums;

/**
 * Why a pre-notice may belong to another incident. Firm evidence suggests too when it cannot be acted on: a new
 * pre-notice that matches several incidents, or one whose vendor edit links it to another incident.
 */
enum IncidentSuggestionReason: string
{
    case SharedId = 'shared_id';
    case DirectLink = 'direct_link';
    case ReleaseWindow = 'release_window';
    case Classifier = 'classifier';

    public function getLabel(): string
    {
        return match ($this) {
            self::SharedId => __('Chung mã CVE/GHSA'),
            self::DirectLink => __('Liên kết trực tiếp'),
            self::ReleaseWindow => __('Đăng quanh giờ phát hành dự kiến'),
            self::Classifier => __('AI cho là cùng sự kiện'),
        };
    }
}

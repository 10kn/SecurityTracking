<?php

namespace App\Enums;

enum AdvisoryKind: string
{
    case Vulnerability = 'vulnerability';
    case PreNotice = 'pre_notice';
    case SecurityRelease = 'security_release';
    case OsAdvisory = 'os_advisory';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vulnerability => __('Lỗ hổng'),
            self::PreNotice => __('Thông báo sớm'),
            self::SecurityRelease => __('Bản phát hành bảo mật'),
            self::OsAdvisory => __('Tin bảo mật hệ điều hành'),
        };
    }
}

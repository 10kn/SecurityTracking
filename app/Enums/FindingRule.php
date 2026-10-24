<?php

namespace App\Enums;

enum FindingRule: string
{
    case RuntimeVulnerable = 'runtime_vulnerable';
    case RuntimeEol = 'runtime_eol';
    case RuntimeOutdatedSecurity = 'runtime_outdated_security';
    case DependencyVulnerable = 'dependency_vulnerable';
    case FrameworkEol = 'framework_eol';
    case PreNotice = 'pre_notice';

    public function getLabel(): string
    {
        return match ($this) {
            self::RuntimeVulnerable => __('Runtime có CVE'),
            self::RuntimeEol => __('Runtime hết hỗ trợ'),
            self::RuntimeOutdatedSecurity => __('Runtime thiếu bản vá'),
            self::DependencyVulnerable => __('Package có lỗ hổng'),
            self::FrameworkEol => __('Framework hết hỗ trợ'),
            self::PreNotice => __('Thông báo sớm'),
        };
    }
}

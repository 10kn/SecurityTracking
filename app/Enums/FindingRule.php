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

    /**
     * The title of a finding of this rule in `$locale` (the current one by default), built from its context, so the
     * admin pages show it in the viewer's language while Google Chat keeps the stored one. Null when the rule has no
     * title of its own or the context lacks a part of it.
     *
     * @param  array<string, mixed>  $context
     */
    public function title(array $context, ?string $locale = null): ?string
    {
        $parts = match ($this) {
            self::RuntimeEol => ['technology', 'branch'],
            self::RuntimeOutdatedSecurity => ['technology', 'branch', 'latest'],
            self::DependencyVulnerable => ['advisory', 'package'],
            default => null,
        };

        if ($parts === null) {
            return null;
        }

        $replace = [];

        foreach ($parts as $part) {
            if (! is_string($context[$part] ?? null)) {
                return null;
            }

            $replace[$part] = $context[$part];
        }

        return match ($this) {
            self::RuntimeEol => __(':technology :branch hết hỗ trợ', $replace, $locale),
            self::RuntimeOutdatedSecurity => __(':technology :branch thiếu bản vá bảo mật, cần nâng lên :latest', $replace, $locale),
            default => __(':advisory trong :package', $replace, $locale),
        };
    }
}

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
    case RunnerImageEol = 'runner_image_eol';
    case ActionRuntimeEol = 'action_runtime_eol';

    public function getLabel(): string
    {
        return match ($this) {
            self::RuntimeVulnerable => __('Runtime có CVE'),
            self::RuntimeEol => __('Runtime hết hỗ trợ'),
            self::RuntimeOutdatedSecurity => __('Runtime thiếu bản vá'),
            self::DependencyVulnerable => __('Package có lỗ hổng'),
            self::FrameworkEol => __('Framework hết hỗ trợ'),
            self::PreNotice => __('Thông báo sớm'),
            self::RunnerImageEol => __('Runner GitHub Actions bị gỡ'),
            self::ActionRuntimeEol => __('Action chạy trên Node hết hỗ trợ'),
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
        $template = $this->titleTemplate();

        if ($template === null) {
            return null;
        }

        $replace = [];

        foreach ($this->titleParts() as $part) {
            if (! is_string($context[$part] ?? null)) {
                return null;
            }

            $replace[$part] = $context[$part];
        }

        return __($template, $replace, $locale);
    }

    /**
     * The translation key of the title, whose placeholders are context keys, e.g. ":technology :branch hết hỗ trợ".
     * Null when the rule has no title of its own.
     */
    public function titleTemplate(): ?string
    {
        return match ($this) {
            self::RuntimeEol => ':technology :branch hết hỗ trợ',
            self::RuntimeOutdatedSecurity => ':technology :branch thiếu bản vá bảo mật, cần nâng lên :latest',
            self::DependencyVulnerable => ':advisory trong :package',
            self::RunnerImageEol => 'Runner :runner bị GitHub gỡ từ :date',
            self::ActionRuntimeEol => ':action chạy trên Node.js :node đã hết hỗ trợ',
            default => null,
        };
    }

    /**
     * The context keys the title is built from, in the order of the template.
     *
     * @return list<string>
     */
    public function titleParts(): array
    {
        preg_match_all('/:(\w+)/', (string) $this->titleTemplate(), $matches);

        return $matches[1];
    }
}

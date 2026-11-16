<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Locale;
use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Services\Notices\NodejsNoticeFeed;
use App\Services\Notices\PhpNetNoticeFeed;
use App\Services\Notices\RubyLangNoticeFeed;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Hướng dẫn sử dụng" screen: how the hub works, when each source is read and when a team is told. The words are
 * on the page; the times and thresholds come from here, as they are set now (config/psm.php with the system settings
 * written over it), so the page never describes a schedule the hub no longer runs.
 *
 * The runs listed here are the ones of routes/console.php: a change there is made here too.
 */
class GuideController extends Controller
{
    public function __invoke(): Response
    {
        $applicationTimezone = (string) config('app.timezone');

        return Inertia::render('guide/index', [
            'timezone' => Locale::current()->timezoneLabel(),
            'vendor_sources' => [
                ['key' => 'php', 'name' => 'PHP', 'website_url' => 'https://www.php.net/', 'feed_url' => PhpNetNoticeFeed::URL],
                ['key' => 'nodejs', 'name' => 'Node.js', 'website_url' => 'https://nodejs.org/en/blog/vulnerability', 'feed_url' => NodejsNoticeFeed::URL],
                ['key' => 'ruby', 'name' => 'Ruby', 'website_url' => 'https://www.ruby-lang.org/en/news/', 'feed_url' => RubyLangNoticeFeed::URL],
            ],
            'schedule' => [
                $this->repeating('notices', 15),
                $this->repeating('catalog', 60),
                $this->repeating('kev', 60, 20),
                $this->daily('packages', ['00:00', '06:00', '12:00', '18:00'], $applicationTimezone),
                $this->daily('end_of_life', ['00:05'], $applicationTimezone),
                $this->daily('runner_images', ['00:15'], $applicationTimezone),
                $this->daily('repository_connections', [(string) config('psm.github.connection_check_time')], (string) config('psm.github.timezone')),
                $this->daily('lockfiles', [(string) config('psm.github.sync_time')], (string) config('psm.github.timezone')),
                $this->daily('digest', [(string) config('psm.digest.time')], (string) config('psm.digest.timezone')),
                $this->repeating('sources', 15),
            ],
            'digest' => [
                'days' => array_values(array_map('intval', (array) config('psm.digest.days'))),
                'weekly_day' => (int) config('psm.digest.weekly_day'),
            ],
            'policy' => [
                'immediate_severities' => $this->severities((array) config('psm.alerts.immediate_severities')),
                'deadlines' => array_map(fn (Severity $severity): array => [
                    'severity' => ['value' => $severity->value, 'label' => $severity->getLabel()],
                    'days' => (int) config("psm.alerts.deadline_days.{$severity->value}"),
                ], [Severity::Critical, Severity::High, Severity::Medium, Severity::Low]),
                'lockfile_summary_threshold' => (int) config('psm.alerts.lockfile_summary_threshold'),
                'patch_hold_hours' => (int) config('psm.incidents.patch_hold_hours'),
                'notice_relevant_days' => (int) config('psm.notices.relevant_days'),
            ],
        ]);
    }

    /**
     * A run every `$everyMinutes` minutes, all day; an hourly one starts `$minute` minutes past the hour.
     *
     * @return array{key: string, every_minutes: int, minute: int, times: list<string>}
     */
    private function repeating(string $key, int $everyMinutes, int $minute = 0): array
    {
        return ['key' => $key, 'every_minutes' => $everyMinutes, 'minute' => $minute, 'times' => []];
    }

    /**
     * Runs at fixed times of day in `$timezone`, shown in the timezone of the reader's language.
     *
     * @param  list<string>  $times
     * @return array{key: string, every_minutes: null, minute: int, times: list<string>}
     */
    private function daily(string $key, array $times, string $timezone): array
    {
        $local = array_map(
            fn (string $time): string => CarbonImmutable::today($timezone)->setTimeFromTimeString($time)->setTimezone(Locale::current()->timezone())->format('H:i'),
            $times,
        );

        sort($local);

        return ['key' => $key, 'every_minutes' => null, 'minute' => 0, 'times' => $local];
    }

    /**
     * @param  array<int, string>  $values
     * @return list<array{value: string, label: string}>
     */
    private function severities(array $values): array
    {
        $severities = array_filter(array_map(fn (string $value): ?Severity => Severity::tryFrom($value), $values));

        usort($severities, fn (Severity $a, Severity $b): int => $b->rank() <=> $a->rank());

        return array_map(fn (Severity $severity): array => ['value' => $severity->value, 'label' => $severity->getLabel()], $severities);
    }
}

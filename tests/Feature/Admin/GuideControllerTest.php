<?php

use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\get;

it('shows when each source runs, in the timezone of the reader', function () {
    actingAsAdmin();
    config(['psm.github.sync_time' => '06:30', 'psm.github.connection_check_time' => '06:00', 'psm.github.timezone' => 'Asia/Ho_Chi_Minh', 'psm.digest.time' => '08:00', 'psm.digest.timezone' => 'Asia/Ho_Chi_Minh']);

    get('/admin/guide')->assertInertia(fn (Assert $page) => $page
        ->component('guide/index')
        ->where('timezone', 'GMT+7')
        ->has('schedule', 11)
        ->where('schedule.0', ['key' => 'notices', 'every_minutes' => 15, 'minute' => 0, 'times' => []])
        ->where('schedule.1', ['key' => 'os_advisories', 'every_minutes' => 30, 'minute' => 0, 'times' => []])
        ->where('schedule.3', ['key' => 'kev', 'every_minutes' => 60, 'minute' => 20, 'times' => []])
        ->where('schedule.4.key', 'packages')
        ->where('schedule.4.times', ['01:00', '07:00', '13:00', '19:00'])
        ->where('schedule.5.times', ['07:05'])
        ->where('schedule.7', ['key' => 'repository_connections', 'every_minutes' => null, 'minute' => 0, 'times' => ['06:00']])
        ->where('schedule.8', ['key' => 'lockfiles', 'every_minutes' => null, 'minute' => 0, 'times' => ['06:30']])
        ->where('schedule.9', ['key' => 'digest', 'every_minutes' => null, 'minute' => 0, 'times' => ['08:00']]));
});

it('shows the alert policy as it is set now', function () {
    actingAsAdmin();
    config([
        'psm.alerts.immediate_severities' => ['high', 'critical'],
        'psm.alerts.deadline_days.critical' => 2,
        'psm.digest.days' => [1, 2, 3, 4, 5],
        'psm.digest.weekly_day' => 1,
    ]);

    get('/admin/guide')->assertInertia(fn (Assert $page) => $page
        ->where('policy.immediate_severities.0.value', 'critical')
        ->where('policy.immediate_severities.1.value', 'high')
        ->where('policy.deadlines.0', ['severity' => ['value' => 'critical', 'label' => 'Critical'], 'days' => 2])
        ->where('digest', ['days' => [1, 2, 3, 4, 5], 'weekly_day' => 1]));
});

it('sends a guest to the login page', function () {
    get('/admin/guide')->assertRedirect('/admin/login');
});

it('shows the official news pages and the feeds polled for each vendor', function () {
    actingAsAdmin();

    get('/admin/guide')->assertInertia(fn (Assert $page) => $page
        ->where('vendor_sources', [
            ['key' => 'php', 'name' => 'PHP', 'website_url' => 'https://www.php.net/', 'feed_url' => 'https://www.php.net/feed.atom'],
            ['key' => 'nodejs', 'name' => 'Node.js', 'website_url' => 'https://nodejs.org/en/blog/vulnerability', 'feed_url' => 'https://nodejs.org/en/feed/vulnerability.xml'],
            ['key' => 'ruby', 'name' => 'Ruby', 'website_url' => 'https://www.ruby-lang.org/en/news/', 'feed_url' => 'https://www.ruby-lang.org/en/feeds/news.rss'],
        ]));
});

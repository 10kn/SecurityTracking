<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Handover 5.2: release feeds hourly; vendor pre-notices every 15 minutes; end-of-life is evaluated daily just after
 * midnight; package advisories (OSV) every 6 hours; failing sources are checked every 15 minutes. The digest time,
 * days and timezone come from config/psm.php (handover section 4). Lockfiles are read from GitHub once a day (D12).
 * The source check pings an external dead man's switch when one is configured, so a stopped scheduler is noticed.
 */
Schedule::command('psm:sync-runtimes')->hourly()->withoutOverlapping();
Schedule::command('psm:watch-notices')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('psm:evaluate-runtimes')->dailyAt('00:05')->withoutOverlapping();
Schedule::command('psm:scan-packages')->everySixHours()->withoutOverlapping();
Schedule::command('psm:check-sources')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->pingOnSuccessIf(filled(config('psm.alerts.scheduler_heartbeat_url')), (string) config('psm.alerts.scheduler_heartbeat_url'));
Schedule::command('psm:sync-lockfiles')
    ->dailyAt(config('psm.github.sync_time'))
    ->timezone(config('psm.github.timezone'))
    ->withoutOverlapping();
Schedule::command('psm:send-digest')
    ->days(config('psm.digest.days'))
    ->at(config('psm.digest.time'))
    ->timezone(config('psm.digest.timezone'))
    ->withoutOverlapping();

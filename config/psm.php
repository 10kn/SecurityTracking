<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Version catalog
    |--------------------------------------------------------------------------
    |
    | psm:sync-runtimes refreshes the version catalog of every technology from endoflife.date and the official release
    | feeds. A technology whose catalog has not synced successfully within `stale_after_minutes` evaluates as unknown,
    | so a broken source never leaves an old "ok" in place.
    |
    */

    'catalog' => [
        'stale_after_minutes' => (int) env('PSM_CATALOG_STALE_AFTER_MINUTES', 180),
        'http_timeout_seconds' => (int) env('PSM_CATALOG_HTTP_TIMEOUT', 20),
        'user_agent' => env('PSM_HTTP_USER_AGENT', 'ProjectSecurityManager/1.0 (internal security monitoring)'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts (Google Chat)
    |--------------------------------------------------------------------------
    |
    | Handover 5.4 and 5.5. Findings whose severity is in `immediate_severities` are sent to the owning team right
    | away, one card per team and issue; every other finding only appears in the daily digest. `runtime_severity` is
    | the severity of each runtime rule. System errors (a source failing past its threshold, a message that could not
    | be delivered) go to the admin space at `admin_webhook`. Every vendor security news item goes once to the space at
    | `news_webhook`, whether or not a team runs its technology, or to the admin space when it is not set. Messages are
    | written in `locale`, whatever language the admin who triggered them uses.
    |
    | A service with at least `lockfile_summary_threshold` vulnerable packages (distinct advisories) to send in one
    | run, e.g. a new service or a large lockfile change, gets one short card saying how many and the highest severity
    | instead of one card per advisory; CISA KEV ones still get their own card. 0 turns the summary off.
    |
    | Each finding has a handling deadline of `deadline_days` after it is first seen, by severity (calendar days). A
    | rise in severity can only bring it forward. An admin marks a finding as read ("Đã đọc") to stop its daily
    | reminder; it is reminded once more when the deadline passes.
    |
    | psm:check-sources also tells the admin, without going through the queue, when a message has waited longer than
    | `stuck_after_minutes` (the queue worker is down). It pings `scheduler_heartbeat_url` after each successful run:
    | point it at an external dead man's switch (e.g. Healthchecks.io) so a stopped scheduler is noticed too.
    |
    | Messages to one space are sent one at a time, at least `space_interval_seconds` apart (Google Chat accepts
    | about one per second per space); a message that has to wait goes back to the queue. A message is tried up to
    | `tries` times over `retry_hours`, with `backoff_seconds` between tries; waiting for its turn is not a try.
    | Each incident has one thread per team: a reply waits up to `opener_wait_minutes` for the card that opens its
    | thread, then opens the thread itself with a full card. Sending a thread message waits at most
    | `incident_lock_wait_ms` for an alert run of the incident's technology, then goes back to the queue.
    |
    */

    'alerts' => [
        'admin_webhook' => env('PSM_ADMIN_CHAT_WEBHOOK'),
        'news_webhook' => env('PSM_ADMIN_NEWS_WEBHOOK'),
        'locale' => env('PSM_ALERT_LOCALE', env('APP_LOCALE', 'vi')),
        'immediate_severities' => array_values(array_filter(array_map('trim', explode(',', (string) env('PSM_IMMEDIATE_SEVERITIES', 'critical,high'))))),
        'runtime_severity' => [
            'runtime_eol' => env('PSM_SEVERITY_RUNTIME_EOL', 'high'),
            'runtime_outdated_security' => env('PSM_SEVERITY_RUNTIME_OUTDATED_SECURITY', 'high'),
        ],
        'immediate_dev_dependencies' => (bool) env('PSM_IMMEDIATE_DEV_DEPENDENCIES', false),
        'lockfile_summary_threshold' => (int) env('PSM_LOCKFILE_SUMMARY_THRESHOLD', 3),
        'tries' => (int) env('PSM_ALERT_TRIES', 5),
        'retry_hours' => (int) env('PSM_ALERT_RETRY_HOURS', 24),
        'backoff_seconds' => [60, 300, 900, 3600],
        'space_interval_seconds' => 1,
        'opener_wait_minutes' => 10,
        'incident_lock_wait_ms' => (int) env('PSM_ALERT_INCIDENT_LOCK_WAIT_MS', 5000),
        'max_listed_items' => 20,
        'deadline_days' => [
            'critical' => (int) env('PSM_DEADLINE_CRITICAL_DAYS', 3),
            'high' => (int) env('PSM_DEADLINE_HIGH_DAYS', 14),
            'medium' => (int) env('PSM_DEADLINE_MEDIUM_DAYS', 30),
            'low' => (int) env('PSM_DEADLINE_LOW_DAYS', 90),
        ],
        'stuck_after_minutes' => (int) env('PSM_ALERT_STUCK_AFTER_MINUTES', 30),
        'scheduler_heartbeat_url' => env('PSM_SCHEDULER_HEARTBEAT_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor pre-notices
    |--------------------------------------------------------------------------
    |
    | psm:watch-notices polls the news feed of each technology (php.net, nodejs.org, ruby-lang.org) every 15 minutes
    | and keeps every entry published within `collect_days` of the poll, release announcements included: a feed's older
    | history is never stored, as it is about versions no project runs any more. An entry without a date is kept, and
    | one already stored keeps following the vendor's edits. `collect_days` is never shorter than `relevant_days`.
    | News has no fixed structure, so only the classifier reads
    | its words: whether it is security news, the vendor's rating, the versions to update to and whether the
    | vulnerability is exploited. No keyword or pattern stands in for it. Its severity is the highest of the
    | classifier, its CVSS score (9.0+ Critical, 7.0+ High, 4.0+ Medium) and a KEV listing.
    |
    | Pre-notices published within `relevant_days` are sent right away to every team running the technology when they
    | are Critical, High or of unknown severity (vendors often give none), one card per incident (see `incidents`);
    | Medium and Low ones only appear in the daily digest. The entries a feed lists on its first successful poll are history and are never sent, except critical
    | ones still within `relevant_days`. A feed that has not been polled successfully within `stale_after_minutes`
    | alerts the admin.
    |
    | When `classifier.api_key` is set, Claude (`classifier.model`) reads each entry published within `collect_days`
    | once, and again when the vendor edits one younger than `relevant_days`: whether it is security news, the
    | severity, the planned release time, the affected branches, the versions to update to, whether it is exploited and
    | a summary in the alert locale. It can drop an entry as not security news unless a CVSS score or a KEV listing says
    | otherwise.
    |
    | Entries wait for the classifier, except those a CVSS score or a KEV listing makes Critical. When the API fails, an
    | entry keeps what it read before, one it never read waits, and both are classified on a later poll. Without an API
    | key, no entry is security news unless its CVSS score or a KEV listing says so. The admin is told right away when
    | the API fails, with the error, once per outage, and again once it classifies an entry. psm:check-sources also
    | tells the admin about an entry still not classified after `classifier.alert_after_minutes`.
    |
    */

    'notices' => [
        'relevant_days' => (int) env('PSM_NOTICE_RELEVANT_DAYS', 14),
        'collect_days' => (int) env('PSM_NOTICE_COLLECT_DAYS', 90),
        'stale_after_minutes' => (int) env('PSM_NOTICE_STALE_AFTER_MINUTES', 120),
        'summary_length' => 500,
        'classifier' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('PSM_NOTICE_CLASSIFIER_MODEL', 'claude-haiku-5-5'),
            'effort' => env('PSM_NOTICE_CLASSIFIER_EFFORT', 'low'),
            'timeout_seconds' => (int) env('PSM_NOTICE_CLASSIFIER_TIMEOUT', 60),
            'alert_after_minutes' => (int) env('PSM_NOTICE_CLASSIFIER_ALERT_AFTER_MINUTES', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Incidents
    |--------------------------------------------------------------------------
    |
    | The pre-notices of one security issue form an incident, and a team gets one card per incident. A pre-notice joins
    | an incident on its own only when it shares a CVE or GHSA id with it or one links to the other. A pre-notice
    | published from `release_window_hours.before` hours before to `release_window_hours.after` hours after the release
    | another incident planned is only suggested to the admin, as is the incident the classifier relates it to.
    |
    | Once a pre-notice of the incident names the version to update to and the catalog lists it as a security release,
    | each team with a service on that branch gets one "Đã có bản vá" card, which also stands for the team's
    | "thiếu bản vá" findings of the branch: they take the incident's severity and deadline and send no card of their
    | own. While a team waits for the fix of an incident of the technology, a new "thiếu bản vá" finding holds its card
    | for `patch_hold_hours`, so the vendor can confirm the version first. The digest lists the incidents still
    | waiting under "Đang chờ bản vá" for `awaiting_patch_days`, with security releases that may be the fix, and asks
    | for a review once `review_after_days` pass without one.
    |
    */

    'incidents' => [
        'patch_hold_hours' => (int) env('PSM_INCIDENT_PATCH_HOLD_HOURS', 6),
        'review_after_days' => (int) env('PSM_INCIDENT_REVIEW_AFTER_DAYS', 14),
        'awaiting_patch_days' => (int) env('PSM_INCIDENT_AWAITING_PATCH_DAYS', 60),
        'release_window_hours' => [
            'before' => (int) env('PSM_INCIDENT_WINDOW_BEFORE_HOURS', 24),
            'after' => (int) env('PSM_INCIDENT_WINDOW_AFTER_HOURS', 48),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Known exploited vulnerabilities (CISA KEV)
    |--------------------------------------------------------------------------
    |
    | psm:sync-kev reads the CISA Known Exploited Vulnerabilities catalog at `url` every hour. A CVE CISA lists is
    | exploited in the wild: a pre-notice or package advisory that names it rises to `severity`, and so do the findings
    | it reaches, whose deadline becomes the earliest of their own, the CISA due date and the deadline of `severity`
    | from now. Being listed tells nothing about a given service, and not being listed never means safe. A pre-notice
    | that only names GitHub advisory ids (GHSA) is matched through the CVE of each id: the package advisories already
    | stored, else the GitHub advisory database at `ghsa_api_url`. When the catalog has not been read successfully
    | within `stale_after_minutes` the admin is alerted.
    |
    */

    'kev' => [
        'url' => env('PSM_KEV_URL', 'https://raw.githubusercontent.com/cisagov/kev-data/main/known_exploited_vulnerabilities.json'),
        'severity' => env('PSM_KEV_SEVERITY', 'critical'),
        'ghsa_api_url' => env('PSM_KEV_GHSA_API_URL', 'https://api.github.com/advisories'),
        'stale_after_minutes' => (int) env('PSM_KEV_STALE_AFTER_MINUTES', 360),
        'http_timeout_seconds' => (int) env('PSM_KEV_HTTP_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Package vulnerabilities (Packagist, RubySec, OSV)
    |--------------------------------------------------------------------------
    |
    | psm:scan-packages matches the packages of every uploaded lockfile every 6 hours, and a deployment is scanned
    | again as soon as its lockfiles change. Composer packages are matched against the Packagist security advisories
    | at `packagist_url` (the source of `composer audit`), `packagist_batch_size` package names per request. Gems are
    | matched against the RubySec advisory database (the source of `bundler-audit`), read from the tarball at
    | `rubysec_url`. npm packages are matched against the OSV API at `osv_url`: package versions in batches of
    | `batch_size` (OSV accepts up to 1000 per request), vulnerabilities not stored yet fetched `concurrency` at a
    | time. When a source has not answered successfully within `stale_after_minutes` the admin is alerted; while it
    | fails, the open findings of its packages stay open.
    |
    */

    'packages' => [
        'packagist_url' => env('PSM_PACKAGIST_URL', 'https://packagist.org'),
        'packagist_batch_size' => (int) env('PSM_PACKAGIST_BATCH_SIZE', 500),
        'rubysec_url' => env('PSM_RUBYSEC_URL', 'https://github.com/rubysec/ruby-advisory-db/archive/refs/heads/master.tar.gz'),
        'osv_url' => env('PSM_OSV_URL', 'https://api.osv.dev/v1'),
        'batch_size' => (int) env('PSM_OSV_BATCH_SIZE', 1000),
        'stale_after_minutes' => (int) env('PSM_PACKAGES_STALE_AFTER_MINUTES', 720),
        'http_timeout_seconds' => (int) env('PSM_PACKAGES_HTTP_TIMEOUT', 30),
        'concurrency' => (int) env('PSM_OSV_CONCURRENCY', 10),
        'summary_length' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lockfiles from GitHub
    |--------------------------------------------------------------------------
    |
    | Handover D12. psm:sync-lockfiles reads, once a day at `sync_time` in `timezone`, the lockfiles of every service
    | with a repository: at the tip of the service's branch (the default branch when none is set), in the service's
    | directory. Services pick their repository from the ones the App may read, listed again when an admin refreshes
    | the list. It signs in as a GitHub App installed on the organisation with read access to contents and metadata
    | only: `app_id` (or the App's client ID), `installation_id`, and the App's private key in a file at
    | `private_key_path` that only the app user can read, outside the web root and never committed. For GitHub
    | Enterprise Server, set `host` and `api_url`. When GitHub has not answered successfully within
    | `stale_after_minutes` the admin is alerted; the lockfiles read last stay in use meanwhile.
    |
    | Lockfiles are searched in the service directory and its subdirectories, at most `lockfile_search.max_depth`
    | levels below it (frontend/package-lock.json is 1 level). Directories named in `skip_directories` are never
    | entered: installed dependencies, and test fixtures, which are often vulnerable on purpose. A service adds its
    | own exclusions in its form. Beyond `max_tree_requests`, `max_lockfiles` or `max_total_kilobytes` the sync stops,
    | keeps the lockfiles read before and alerts the admin.
    |
    */

    'github' => [
        'app_id' => env('PSM_GITHUB_APP_ID'),
        'installation_id' => env('PSM_GITHUB_INSTALLATION_ID'),
        'private_key_path' => env('PSM_GITHUB_PRIVATE_KEY_PATH'),
        'host' => env('PSM_GITHUB_HOST', 'github.com'),
        'api_url' => env('PSM_GITHUB_API_URL', 'https://api.github.com'),
        'http_timeout_seconds' => (int) env('PSM_GITHUB_HTTP_TIMEOUT', 30),
        'sync_time' => env('PSM_GITHUB_SYNC_TIME', '06:30'),
        'timezone' => env('PSM_GITHUB_SYNC_TIMEZONE', 'Asia/Ho_Chi_Minh'),
        'stale_after_minutes' => (int) env('PSM_GITHUB_STALE_AFTER_MINUTES', 1500),

        'lockfile_search' => [
            'max_depth' => 4,
            'max_tree_requests' => 50,
            'max_lockfiles' => 20,
            'max_total_kilobytes' => 102400,
            'skip_directories' => ['node_modules', 'vendor', 'bower_components', '.git', '.yarn', 'test', 'tests', '__tests__', 'spec', 'fixtures', '__fixtures__', 'testdata'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily digest
    |--------------------------------------------------------------------------
    |
    | Each team gets at most one card at `time` on `days` (0 = Sunday … 6 = Saturday) in `timezone`, and only when
    | there is something to read: new findings, open findings at the immediate severities (every day while open),
    | Medium and Low pre-notices, a runtime crossing one of `eol_reminder_days` before its end of life, a lockfile that
    | became unreadable. On `weekly_day` (which must be one of `days`) it is replaced by the weekly summary: everything
    | still open, what closed in the last seven days, runtimes reaching end of life within `eol_warning_days` and the
    | counts of runtimes with missing data or a newer release. A team with nothing at all gets nothing.
    |
    */

    'digest' => [
        'time' => env('PSM_DIGEST_TIME', '08:00'),
        'days' => array_map('intval', explode(',', (string) env('PSM_DIGEST_DAYS', '0,1,2,3,4,5,6'))),
        'timezone' => env('PSM_DIGEST_TIMEZONE', 'Asia/Ho_Chi_Minh'),
        'weekly_day' => (int) env('PSM_DIGEST_WEEKLY_DAY', 1),
        'eol_warning_days' => (int) env('PSM_EOL_WARNING_DAYS', 30),
        'eol_reminder_days' => array_map('intval', explode(',', (string) env('PSM_EOL_REMINDER_DAYS', '30,7,1'))),
    ],

];

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
    | be delivered) go to the admin space at `admin_webhook`. Messages are written in `locale`, whatever language the
    | admin who triggered them uses.
    |
    */

    'alerts' => [
        'admin_webhook' => env('PSM_ADMIN_CHAT_WEBHOOK'),
        'locale' => env('PSM_ALERT_LOCALE', env('APP_LOCALE', 'vi')),
        'immediate_severities' => array_values(array_filter(array_map('trim', explode(',', (string) env('PSM_IMMEDIATE_SEVERITIES', 'critical,high'))))),
        'runtime_severity' => [
            'runtime_eol' => env('PSM_SEVERITY_RUNTIME_EOL', 'high'),
            'runtime_outdated_security' => env('PSM_SEVERITY_RUNTIME_OUTDATED_SECURITY', 'high'),
        ],
        'immediate_dev_dependencies' => (bool) env('PSM_IMMEDIATE_DEV_DEPENDENCIES', false),
        'tries' => (int) env('PSM_ALERT_TRIES', 5),
        'backoff_seconds' => [60, 300, 900, 3600],
        'max_listed_items' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor pre-notices
    |--------------------------------------------------------------------------
    |
    | psm:watch-notices polls the security news feed of each technology (php.net, nodejs.org, ruby-lang.org) every
    | 15 minutes. An entry is kept when its title or text contains one of `keywords` (case does
    | not matter; a feed that only publishes security news keeps every entry), unless its title matches one of
    | `ignored_title_patterns`: release announcements are left to the hourly catalog sync, which already alerts on
    | each security release with the exact version to upgrade to. Entries published within `max_age_hours` are sent
    | to every team running the technology, so the first poll does not replay the feed's history. A runtime missing
    | a security fix is Critical instead of High while a critical pre-notice of its technology is younger than
    | `escalation_days`. A feed that has not been polled successfully within `stale_after_minutes` alerts the admin.
    |
    | When `classifier.api_key` is set, Claude (`classifier.model`) reads each kept entry younger than
    | `escalation_days` once, and again when the vendor edits it: the planned release time, the affected branches and
    | a summary in the alert locale. Its severity can only raise the one the keywords give, and it can drop an entry
    | as not security news only when the keywords found no severity. When the API fails, the entry keeps the keyword
    | result and is classified on a later poll.
    |
    */

    'notices' => [
        'keywords' => array_values(array_filter(array_map('trim', explode(',', (string) env('PSM_NOTICE_KEYWORDS', 'security,vulnerab,cve-,exploit,advance notice,pre-announce,pre-notice'))))),
        'ignored_title_patterns' => ['/\breleased!?\s*$/i'],
        'max_age_hours' => (int) env('PSM_NOTICE_MAX_AGE_HOURS', 72),
        'escalation_days' => (int) env('PSM_NOTICE_ESCALATION_DAYS', 14),
        'stale_after_minutes' => (int) env('PSM_NOTICE_STALE_AFTER_MINUTES', 120),
        'summary_length' => 500,
        'classifier' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('PSM_NOTICE_CLASSIFIER_MODEL', 'claude-haiku-5-5'),
            'effort' => env('PSM_NOTICE_CLASSIFIER_EFFORT', 'low'),
            'timeout_seconds' => (int) env('PSM_NOTICE_CLASSIFIER_TIMEOUT', 60),
        ],
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily digest
    |--------------------------------------------------------------------------
    |
    | Each team gets one card at `time` on `days` (0 = Sunday … 6 = Saturday) in `timezone`: new findings since the
    | previous digest, findings still open, findings closed automatically, runtimes with missing data, outdated and
    | soon end-of-life runtimes. A team with nothing to report gets nothing.
    |
    */

    'digest' => [
        'time' => env('PSM_DIGEST_TIME', '08:00'),
        'days' => array_map('intval', explode(',', (string) env('PSM_DIGEST_DAYS', '0,1,2,3,4,5,6'))),
        'timezone' => env('PSM_DIGEST_TIMEZONE', 'Asia/Ho_Chi_Minh'),
        'eol_warning_days' => (int) env('PSM_EOL_WARNING_DAYS', 30),
    ],

];

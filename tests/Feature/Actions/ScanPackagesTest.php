<?php

use App\Actions\ScanPackages;
use App\Actions\SendDailyDigest;
use App\Actions\SendFindingAlerts;
use App\Actions\SyncComponents;
use App\Enums\FindingRule;
use App\Enums\FindingState;
use App\Enums\Severity;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Service;
use App\Models\SourceHeartbeat;
use App\Models\Team;
use App\Services\AlertPolicy;
use App\Services\FindingData;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->travelTo('2026-10-23 10:00:00');
    fakeGoogleChat();
    $team = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->deployment = Deployment::factory()->for(Service::factory()->for(Project::factory()->for($team))->state(['name' => 'shop-api']))->create();
});

/**
 * Uploads fixture lockfiles to the deployment and scans it.
 */
function scanWithLockfiles(Deployment $deployment, string ...$names): void
{
    foreach ($names as $name) {
        $path = str_starts_with($name, 'package-lock') ? 'package-lock.json' : $name;
        $content = lockfileFixture($name);
        $lockfile = $deployment->lockfiles()->firstOrNew(['path' => $path]);
        $lockfile->fill(Lockfile::factory()->withContent($path, $content)->raw(['deployment_id' => $deployment->id]))->save();
    }

    app(SyncComponents::class)->handle($deployment);
    app(ScanPackages::class)->handle([$deployment->id]);
}

it('opens one finding per package and advisory, grouped by advisory', function () {
    fakePackagist();

    scanWithLockfiles($this->deployment, 'composer.lock');

    expect(Finding::where('rule', FindingRule::DependencyVulnerable)->orderBy('title')->pluck('severity', 'title')->all())->toEqual([
        'GHSA-2x45-7fc3-mxwq trong firebase/php-jwt' => Severity::Low,
        'GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt' => Severity::Critical,
        'GHSA-r39x-jcww-82v6 trong symfony/process' => Severity::Medium,
        'GHSA-vvj3-c3rp-c85p trong phpunit/phpunit' => Severity::High,
    ]);

    $finding = Finding::where('title', 'GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt')->sole();
    expect($finding)
        ->fingerprint->toBe(FindingData::fingerprint(FindingRule::DependencyVulnerable, $this->deployment->id, 'package:packagist/firebase/php-jwt', 'PKSA-2kqm-ps5x-s4f5'))
        ->group_key->toBe('dependency_vulnerable:PKSA-2kqm-ps5x-s4f5')
        ->team_id->toBe($this->deployment->service->project->team_id)
        ->advisory->source->toBe('packagist')
        ->advisory->external_id->toBe('PKSA-2kqm-ps5x-s4f5')
        ->component->name->toBe('firebase/php-jwt')
        ->and($finding->context)->toMatchArray([
            'ecosystem' => 'packagist',
            'package' => 'firebase/php-jwt',
            'version' => 'v5.5.1',
            'fixed' => '6.0.0',
            'lockfile' => 'composer.lock',
            'is_dev' => false,
            'aliases' => ['CVE-2021-46743'],
            'advisory' => 'GHSA-8xf4-w7qw-pjjw',
            'source_url' => 'https://github.com/advisories/GHSA-8xf4-w7qw-pjjw',
            'detail' => 'firebase/php-jwt v5.5.1 · nâng lên 6.0.0 · composer.lock',
        ]);
});

it('lists every version of a package that the same advisory affects in one finding', function () {
    fakeOsv();

    scanWithLockfiles($this->deployment, 'package-lock.json');

    $finding = Finding::where('title', 'GHSA-xvch-5gv4-984h trong minimist')->sole();
    expect($finding->context)->toMatchArray([
        'version' => '0.0.10, 1.2.0',
        'fixed' => '0.2.4, 1.2.6',
        'is_dev' => false,
        'detail' => 'minimist 0.0.10, 1.2.0 · nâng lên 0.2.4, 1.2.6 · package-lock.json',
    ])
        ->and($finding->component->version)->toBe('0.0.10')
        ->and(Finding::where('title', 'GHSA-67hx-6x53-jw92 trong @babel/traverse')->sole()->context['detail'])
        ->toBe('@babel/traverse 7.23.0 · nâng lên 7.23.2 · package-lock.json · chỉ dùng khi phát triển');
});

it('scans Gemfile.lock gems by their plain version', function () {
    fakeOsv();

    scanWithLockfiles($this->deployment, 'Gemfile.lock');

    expect(Finding::orderBy('title')->pluck('title')->all())->toBe([
        'GHSA-2vqw-3mp8-cgmx trong puma',
        'GHSA-735f-pc8j-v9w8 trong google-protobuf',
        'GHSA-qpgp-93vx-g8v8 trong puma',
    ]);
});

it('sends a dev dependency only in the digest unless configured otherwise', function () {
    fakeOsv();
    scanWithLockfiles($this->deployment, 'package-lock.json');

    app(SendFindingAlerts::class)->handle();

    $alerts = chatMessagesTo('COMMERCE')->map(fn (array $message): string => chatJson($message));
    expect($alerts->join(''))->toContain('GHSA-xvch-5gv4-984h', 'GHSA-r5fr-rjxr-66jc')->not->toContain('GHSA-67hx-6x53-jw92');

    app(SendDailyDigest::class)->handle();
    expect(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('[Critical] GHSA-67hx-6x53-jw92 trong @babel/traverse');

    config(['psm.alerts.immediate_dev_dependencies' => true]);
    app(SendFindingAlerts::class)->handle();
    expect(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('GHSA-67hx-6x53-jw92', '@babel/traverse 7.23.0');
});

it('never sends a finding of unknown severity right away', function () {
    $finding = Finding::factory()->for($this->deployment)->create(['rule' => FindingRule::DependencyVulnerable, 'severity' => Severity::Unknown]);

    expect(app(AlertPolicy::class)->awaitsImmediateAlert($finding))->toBeFalse()
        ->and(app(SendFindingAlerts::class)->handle())->toBe(0);
});

it('resolves a finding once the package is upgraded past the fix', function () {
    fakePackagist();
    scanWithLockfiles($this->deployment, 'composer.lock');

    $upgraded = str_replace('"version": "v5.5.1"', '"version": "v7.0.0"', lockfileFixture('composer.lock'));
    $this->deployment->lockfiles()->sole()->update(['content' => $upgraded, 'content_hash' => hash('sha256', $upgraded)]);
    app(SyncComponents::class)->handle($this->deployment);
    app(ScanPackages::class)->handle([$this->deployment->id]);

    expect(Finding::open()->pluck('title')->sort()->values()->all())->toBe([
        'GHSA-r39x-jcww-82v6 trong symfony/process',
        'GHSA-vvj3-c3rp-c85p trong phpunit/phpunit',
    ]);
});

it('keeps the findings of a failing source open and still syncs the packages of the other', function () {
    Sleep::fake();
    $failing = false;
    fakePackagistOutage($failing);
    fakePackagist();
    fakeOsv();
    scanWithLockfiles($this->deployment, 'composer.lock', 'Gemfile.lock');
    expect(SourceHeartbeat::orderBy('source')->get(['source', 'name', 'threshold_minutes'])->toArray())->toBe([
        ['source' => 'osv', 'name' => 'OSV', 'threshold_minutes' => 720],
        ['source' => 'packagist', 'name' => 'Packagist', 'threshold_minutes' => 720],
    ]);

    $failing = true;
    $this->travel(1)->hours();
    $upgraded = str_replace('"version": "v5.5.1"', '"version": "v7.0.0"', lockfileFixture('composer.lock'));
    $this->deployment->lockfiles()->where('path', 'composer.lock')->update(['content' => $upgraded, 'content_hash' => hash('sha256', $upgraded)]);
    $this->deployment->lockfiles()->where('path', 'Gemfile.lock')->delete();
    app(SyncComponents::class)->handle($this->deployment);

    expect(fn () => app(ScanPackages::class)->handle([$this->deployment->id]))->toThrow(RequestException::class);

    expect(Finding::where('title', 'like', '%firebase/php-jwt')->pluck('state')->unique()->all())->toBe([FindingState::Open])
        ->and(Finding::where('title', 'like', '%puma')->pluck('state')->unique()->all())->toBe([FindingState::Resolved])
        ->and(SourceHeartbeat::where('source', 'packagist')->sole())
        ->last_failure_at->toEqual(now())
        ->last_error->toContain('RequestException')
        ->and(SourceHeartbeat::where('source', 'osv')->sole()->last_failure_at)->toBeNull();
});

it('alerts the admin once when OSV keeps failing past its threshold', function () {
    Sleep::fake();
    $failing = false;
    fakeOsvOutage($failing);
    fakeOsv();
    scanWithLockfiles($this->deployment, 'Gemfile.lock');

    $failing = true;
    foreach ([1, 2] as $scan) {
        $this->travel(7)->hours();
        rescue(fn () => app(ScanPackages::class)->handle([$this->deployment->id]), report: false);
        artisan('psm:check-sources')->assertSuccessful();
    }
    artisan('psm:check-sources')->assertSuccessful();

    expect(chatMessagesTo('ADMIN'))->toHaveCount(1)
        ->and(chatJson(chatMessagesTo('ADMIN')->sole()))->toContain('Nguồn OSV lỗi quá ngưỡng');
});

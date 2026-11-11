<?php

use App\Actions\EvaluateWorkflows;
use App\Actions\ScanPackages;
use App\Actions\SyncServiceLockfiles;
use App\Enums\Ecosystem;
use App\Enums\FindingRule;
use App\Enums\FindingState;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Finding;
use App\Models\GitHubActionVersion;
use App\Models\GitHubRunnerImage;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\RuntimeBranch;
use App\Models\Service;
use App\Models\SourceHeartbeat;
use App\Models\Team;
use App\Services\Packages\OsvAdvisory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const CHANGED_FILES_V35 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const CHANGED_FILES_V46 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const DOWNLOAD_ARTIFACT_V410 = 'cccccccccccccccccccccccccccccccccccccccc';
const DOWNLOAD_ARTIFACT_V413 = 'dddddddddddddddddddddddddddddddddddddddd';

const CI_WORKFLOW = <<<'YAML'
name: CI
on: push
jobs:
  build:
    runs-on: macos-13
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4.1.0
      - uses: tj-actions/changed-files@v35
      - uses: actions/download-artifact@cccccccccccccccccccccccccccccccccccccccc
      - uses: actions/upload-artifact@v3
      - uses: acme/private-action@main
YAML;

beforeEach(function () {
    $this->travelTo('2026-11-11 10:00:00');
    fakeGoogleChat();
    $team = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $project = Project::factory()->for($team)->create(['name' => 'Shop']);
    $this->api = Service::factory()->for($project)->syncingLockfiles('acme/shop')->create(['name' => 'shop-api', 'repository_path' => 'apps/api']);
    $this->web = Service::factory()->for($project)->syncingLockfiles('acme/shop')->create(['name' => 'shop-web', 'repository_path' => 'apps/web']);

    GitHubRunnerImage::factory()->create(['name' => 'macos-13', 'eol_from' => '2025-12-04', 'is_eol' => true]);
    GitHubRunnerImage::factory()->create(['name' => 'ubuntu-24.04']);
    SourceHeartbeat::factory()->create(['source' => GitHubRunnerImage::SOURCE_KEY, 'threshold_minutes' => 2880, 'last_success_at' => now()]);
    RuntimeBranch::factory()->create(['technology' => Technology::Nodejs, 'branch' => '16', 'is_eol' => true, 'security_support_until' => '2023-09-11']);
    RuntimeBranch::factory()->create(['technology' => Technology::Nodejs, 'branch' => '24', 'is_eol' => false, 'security_support_until' => '2028-04-30']);
    SourceHeartbeat::factory()->create(['source' => Technology::Nodejs->catalogSourceKey(), 'threshold_minutes' => 180, 'last_success_at' => now()]);
});

/**
 * Fakes the tags of the actions CI_WORKFLOW uses (acme/private-action is not readable) and the action.yml of
 * actions/upload-artifact, which runs on Node 16, then OSV and GitHub with the repository files, next to the
 * directories of both services.
 *
 * @param  array<string, string>  $files
 */
function fakeActionRepositories(array $files): void
{
    $tags = fn (array $tags) => Http::response(collect($tags)->map(fn (string $sha, string $name): array => ['name' => $name, 'commit' => ['sha' => $sha]])->values()->all());

    Http::fake([
        'https://api.github.com/repos/tj-actions/changed-files/tags*' => $tags(['v46.0.1' => CHANGED_FILES_V46, 'v46' => CHANGED_FILES_V46, 'v35.7.1' => CHANGED_FILES_V35, 'v35' => CHANGED_FILES_V35]),
        'https://api.github.com/repos/actions/download-artifact/tags*' => $tags(['v4.1.3' => DOWNLOAD_ARTIFACT_V413, 'v4' => DOWNLOAD_ARTIFACT_V413, 'v4.1.0' => DOWNLOAD_ARTIFACT_V410]),
        'https://api.github.com/repos/actions/checkout/tags*' => $tags(['v4.2.2' => str_repeat('e', 40), 'v4.2' => str_repeat('e', 40), 'v4' => str_repeat('e', 40)]),
        'https://api.github.com/repos/actions/upload-artifact/tags*' => $tags(['v3.1.3' => str_repeat('f', 40), 'v3' => str_repeat('f', 40)]),
        'https://api.github.com/repos/*/tags*' => Http::response(['message' => 'Not Found'], 404),
        'https://api.github.com/repos/actions/upload-artifact/contents/action.yml*' => Http::response("name: Upload\nruns:\n  using: node16\n  main: dist/index.js\n"),
    ]);
    fakeOsv();
    fakeGitHub(['apps/api/README.md' => '# api', 'apps/web/README.md' => '# web', ...$files]);
}

it('reads the workflows at the root of the repository for every service of it, and finds the vulnerable actions', function () {
    fakeActionRepositories([
        'apps/api/composer.lock' => '{"packages":[]}',
        '.github/workflows/ci.yml' => CI_WORKFLOW,
        '.github/dependabot.yml' => 'version: 2',
        '.github/ISSUE_TEMPLATE/bug.yml' => 'name: Bug',
    ]);

    app(SyncServiceLockfiles::class)->handle($this->api);
    app(SyncServiceLockfiles::class)->handle($this->web);

    expect(Lockfile::whereRelation('deployment', 'service_id', $this->api->id)->orderBy('path')->pluck('ecosystem', 'path')->map->value->all())
        ->toBe(['.github/workflows/ci.yml' => 'github_actions', 'composer.lock' => 'packagist'])
        ->and(Lockfile::whereRelation('deployment', 'service_id', $this->web->id)->pluck('path')->all())->toBe(['.github/workflows/ci.yml']);

    $vulnerable = Finding::where('rule', FindingRule::DependencyVulnerable)->where('deployment_id', $this->api->productionDeployment->id)->orderBy('title')->get();

    expect($vulnerable->pluck('severity', 'title')->all())->toEqual([
        'GHSA-cxww-7g56-2vh6 trong actions/download-artifact' => Severity::High,
        'GHSA-mrrh-fwg8-r2c3 trong tj-actions/changed-files' => Severity::High,
    ])
        ->and($vulnerable->firstWhere('context.package', 'tj-actions/changed-files')->context)->toMatchArray([
            'ecosystem' => 'github_actions',
            'version' => 'v35',
            'fixed' => '46.0.1',
            'lockfile' => '.github/workflows/ci.yml',
            'aliases' => ['CVE-2025-30066'],
            'detail' => 'tj-actions/changed-files@v35 (35.7.1) · nâng lên 46.0.1 · .github/workflows/ci.yml',
        ])
        ->and($vulnerable->firstWhere('context.package', 'actions/download-artifact')->context['detail'])
        ->toBe('actions/download-artifact@ccccccc (4.1.0) · nâng lên 4.1.3 · .github/workflows/ci.yml')
        ->and(Finding::where('rule', FindingRule::DependencyVulnerable)->where('deployment_id', $this->web->productionDeployment->id)->count())->toBe(2);

    expect(GitHubActionVersion::orderBy('action')->get()->mapWithKeys(fn (GitHubActionVersion $row): array => ["{$row->action}@{$row->ref}" => [$row->version, $row->runs_using]])->all())->toBe([
        'acme/private-action@main' => [null, null],
        'actions/checkout@v4' => ['4.2.2', null],
        'actions/download-artifact@'.DOWNLOAD_ARTIFACT_V410 => ['4.1.0', null],
        'actions/setup-node@v4.1.0' => ['4.1.0', null],
        'actions/upload-artifact@v3' => ['3.1.3', 'node16'],
        'tj-actions/changed-files@v35' => ['35.7.1', null],
    ])
        // An exact release is not looked up, and each repository's tags are read once per scan.
        ->and(gitHubRequests('repos/actions/setup-node/tags'))->toHaveCount(0)
        ->and(gitHubRequests('repos/tj-actions/changed-files/tags'))->toHaveCount(1);

    // A card per advisory after each sync: the second one adds the other service of the repository. The lifecycle
    // findings wait for the digest.
    expect(chatMessagesTo('COMMERCE'))->toHaveCount(4)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('shop-api', 'shop-web');
});

it('opens digest-only findings for a runner GitHub removed and an action on an end-of-life Node.js', function () {
    fakeActionRepositories(['.github/workflows/ci.yml' => CI_WORKFLOW]);

    app(SyncServiceLockfiles::class)->handle($this->api);

    $deploymentId = $this->api->productionDeployment->id;
    $runner = Finding::where('rule', FindingRule::RunnerImageEol)->where('deployment_id', $deploymentId)->sole();
    $runtime = Finding::where('rule', FindingRule::ActionRuntimeEol)->where('deployment_id', $deploymentId)->sole();

    expect($runner)
        ->title->toBe('Runner macos-13 bị GitHub gỡ từ 2025-12-04')
        ->severity->toBe(Severity::Medium)
        ->and($runner->sla_due_at->setTimezone(config('psm.digest.timezone'))->toDateTimeString())->toBe('2025-12-04 00:00:00')
        ->and($runner->context)->toMatchArray(['image' => 'macos-13', 'is_removed' => true, 'lockfiles' => ['.github/workflows/ci.yml']])
        ->and($runtime)
        ->title->toBe('actions/upload-artifact@v3 (3.1.3) chạy trên Node.js 16 đã hết hỗ trợ')
        ->severity->toBe(Severity::Medium)
        ->and($runtime->component->name)->toBe('actions/upload-artifact');

    expect(chatMessagesTo('COMMERCE')->map(fn (array $message): string => chatJson($message))->filter(fn (string $json): bool => str_contains($json, 'macos-13') || str_contains($json, 'Node.js 16')))->toBeEmpty();
});

it('resolves the findings of a workflow once it is fixed', function () {
    fakeActionRepositories(['.github/workflows/ci.yml' => CI_WORKFLOW]);
    app(SyncServiceLockfiles::class)->handle($this->api);

    Http::swap(new HttpFactory);
    fakeGoogleChat();
    fakeActionRepositories(['.github/workflows/ci.yml' => <<<'YAML'
        jobs:
          build:
            runs-on: ubuntu-24.04
            steps:
              - uses: tj-actions/changed-files@v46
        YAML]);
    app(SyncServiceLockfiles::class)->handle($this->api);

    expect(Finding::where('deployment_id', $this->api->productionDeployment->id)->where('state', FindingState::Open)->count())->toBe(0)
        ->and(Finding::where('deployment_id', $this->api->productionDeployment->id)->where('state', FindingState::Resolved)->pluck('rule')->map->value->sort()->values()->all())
        ->toBe(['action_runtime_eol', 'dependency_vulnerable', 'dependency_vulnerable', 'runner_image_eol']);
});

it('keeps the workflows read before when reading them is turned off, then drops them on the next sync', function () {
    fakeActionRepositories(['.github/workflows/ci.yml' => CI_WORKFLOW]);
    app(SyncServiceLockfiles::class)->handle($this->api);

    config(['psm.github_actions.enabled' => false]);
    app(SyncServiceLockfiles::class)->handle($this->api);

    expect(Lockfile::whereRelation('deployment', 'service_id', $this->api->id)->where('ecosystem', Ecosystem::GitHubActions)->count())->toBe(0)
        ->and(Finding::where('deployment_id', $this->api->productionDeployment->id)->where('state', FindingState::Open)->count())->toBe(0);
});

it('checks a moving reference again only after the recheck interval', function () {
    fakeActionRepositories(['.github/workflows/ci.yml' => CI_WORKFLOW]);
    app(SyncServiceLockfiles::class)->handle($this->api);
    $deploymentId = $this->api->productionDeployment->id;

    app(ScanPackages::class)->handle([$deploymentId]);
    expect(gitHubRequests('repos/tj-actions/changed-files/tags'))->toHaveCount(1);

    $this->travel(config('psm.github_actions.recheck_minutes') + 1)->minutes();
    app(ScanPackages::class)->handle([$deploymentId]);
    expect(gitHubRequests('repos/tj-actions/changed-files/tags'))->toHaveCount(2);
});

it('keeps the lifecycle findings open while their catalogs are stale', function () {
    fakeActionRepositories(['.github/workflows/ci.yml' => CI_WORKFLOW]);
    app(SyncServiceLockfiles::class)->handle($this->api);

    $this->travel(3)->days();
    GitHubRunnerImage::where('name', 'macos-13')->delete();
    RuntimeBranch::where('technology', Technology::Nodejs)->where('branch', '16')->delete();
    app(EvaluateWorkflows::class)->handle([$this->api->productionDeployment->id]);

    expect(Finding::where('deployment_id', $this->api->productionDeployment->id)->whereIn('rule', [FindingRule::RunnerImageEol, FindingRule::ActionRuntimeEol])->where('state', FindingState::Open)->count())->toBe(2);
});

it('compares versions with the OSV ranges of an advisory', function (string $version, bool $isAffected) {
    $ranges = OsvAdvisory::affectedRanges(['affected' => [
        ['package' => ['ecosystem' => 'GitHub Actions', 'name' => 'Acme/Action'], 'ranges' => [
            ['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => '2.0.0']]],
            ['type' => 'ECOSYSTEM', 'events' => [['introduced' => '3.0.0'], ['last_affected' => '3.2.0']]],
            ['type' => 'GIT', 'events' => [['introduced' => '0'], ['fixed' => 'abc123']]],
        ], 'versions' => ['4.0.1']],
    ]]);

    expect(OsvAdvisory::affects($ranges, Ecosystem::GitHubActions, 'acme/action', $version))->toBe($isAffected);
})->with([
    ['1.9.9', true],
    ['v1.0', true],
    ['2.0.0', false],
    ['2.5.0', false],
    ['3.0.0', true],
    ['3.2.0', true],
    ['3.2.1', false],
    ['4.0.1', true],
    ['4.0.2', false],
]);

it('sends no request for tags when no workflow uses a moving reference', function () {
    fakeActionRepositories(['.github/workflows/ci.yml' => "jobs:\n  a:\n    runs-on: ubuntu-24.04\n    steps:\n      - uses: actions/setup-node@v4.1.0\n"]);

    app(SyncServiceLockfiles::class)->handle($this->api);

    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/tags'))->count())->toBe(0);
});

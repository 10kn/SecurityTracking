<?php

use App\Enums\LinuxDistribution;
use App\Enums\Severity;
use App\Models\Advisory;
use App\Models\SourceHeartbeat;
use App\Services\OsAdvisories\OsReleaseSupport;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->travelTo('2026-11-09 10:00:00');
    Advisory::factory()->osAdvisory(LinuxDistribution::Ubuntu)->create([
        'external_id' => 'USN-8999-1',
        'title' => 'USN-8999-1: Pillow vulnerabilities',
        'severity' => Severity::Medium,
        'aliases' => ['CVE-2026-54058', 'CVE-2026-54059', 'CVE-2026-54060', 'CVE-2026-55379', 'CVE-2026-55380', 'CVE-2026-59197'],
        'os_releases' => ['24.04', '22.04'],
        'fixed_packages' => [['release' => '24.04', 'name' => 'pillow', 'version' => '10.2.0-1ubuntu1.4'], ['release' => '22.04', 'name' => 'pillow', 'version' => '9.0.1-1ubuntu0.6']],
        'published_at' => '2026-11-04 15:49:54',
        'url' => 'https://ubuntu.com/security/notices/USN-8999-1',
    ]);
    Advisory::factory()->osAdvisory(LinuxDistribution::AmazonLinux2)->create([
        'external_id' => 'ALAS2ECS-2026-152',
        'title' => 'ALAS2ECS-2026-152: containerd',
        'severity' => Severity::High,
        'aliases' => ['CVE-2026-111943'],
        'known_exploited' => [['cve' => 'CVE-2026-111943', 'name' => 'containerd', 'date_added' => '2026-11-02', 'due_date' => null, 'listed' => true]],
        'os_releases' => ['2'],
        'fixed_packages' => [['release' => '2', 'name' => 'containerd', 'version' => null]],
        'published_at' => '2026-10-30 23:20:00',
    ]);
    Advisory::factory()->create(['title' => 'A package advisory']);
});

it('lists the operating system advisories newest first, marking a release past its end of life', function () {
    actingAsAdmin();
    Http::fake(['https://endoflife.date/api/v1/products/amazon-linux/' => Http::response(osFixture('endoflife-amazon-linux.json'))]);
    app(OsReleaseSupport::class)->refresh(LinuxDistribution::AmazonLinux2);
    SourceHeartbeat::factory()->create(['source' => 'os:ubuntu', 'name' => 'Tin bảo mật Ubuntu', 'threshold_minutes' => 180, 'last_success_at' => now()->subMinutes(20)]);

    get('/admin/os-advisories')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('os-advisories/index')
            ->has('advisories.data', 2)
            ->where('advisories.data.0.reference', 'USN-8999-1')
            ->where('advisories.data.0.title', 'Pillow vulnerabilities')
            ->where('advisories.data.0.os.label', 'Ubuntu')
            ->where('advisories.data.0.packages', ['pillow'])
            ->where('advisories.data.0.cves', ['CVE-2026-54058', 'CVE-2026-54059', 'CVE-2026-54060', 'CVE-2026-55379'])
            ->where('advisories.data.0.more_cves', 2)
            ->where('advisories.data.0.published', '04/11/2026')
            ->where('advisories.data.0.known_exploited', null)
            ->where('advisories.data.1.reference', 'ALAS2ECS-2026-152')
            ->where('advisories.data.1.releases', [['name' => '2', 'is_eol' => true, 'eol_from' => '30/06/2026']])
            ->where('advisories.data.1.known_exploited', ['CVE-2026-111943'])
            ->where('sources.2.label', 'Ubuntu')
            ->where('sources.2.is_stale', false)
            ->where('sources.3.is_stale', true)
            ->where('releases', []));
});

it('filters by operating system, release, severity and CISA KEV', function () {
    actingAsAdmin();

    get('/admin/os-advisories?os=ubuntu&release=22.04')
        ->assertInertia(fn (Assert $page) => $page
            ->has('advisories.data', 1)
            ->where('advisories.data.0.reference', 'USN-8999-1')
            ->where('releases', [['value' => '24.04', 'label' => 'Ubuntu 24.04'], ['value' => '22.04', 'label' => 'Ubuntu 22.04']])
            ->where('table.os', 'ubuntu')
            ->where('table.release', '22.04'));

    get('/admin/os-advisories?os=ubuntu&release=20.04')->assertInertia(fn (Assert $page) => $page->has('advisories.data', 0));
    get('/admin/os-advisories?severity=high')->assertInertia(fn (Assert $page) => $page->has('advisories.data', 1)->where('advisories.data.0.reference', 'ALAS2ECS-2026-152'));
    get('/admin/os-advisories?kev=1')->assertInertia(fn (Assert $page) => $page->has('advisories.data', 1)->where('advisories.data.0.reference', 'ALAS2ECS-2026-152'));
});

it('finds an advisory by its id, a CVE id or a fixed package', function (string $search, string $reference) {
    actingAsAdmin();

    get('/admin/os-advisories?search='.urlencode($search))
        ->assertInertia(fn (Assert $page) => $page->has('advisories.data', 1)->where('advisories.data.0.reference', $reference));
})->with([
    'id' => ['USN-8999', 'USN-8999-1'],
    'CVE id' => ['CVE-2026-111943', 'ALAS2ECS-2026-152'],
    'package' => ['pillow', 'USN-8999-1'],
]);

it('sorts by severity, Critical first', function () {
    actingAsAdmin();

    get('/admin/os-advisories?sort=severity')
        ->assertInertia(fn (Assert $page) => $page->where('advisories.data.0.reference', 'ALAS2ECS-2026-152'));
});

it('is only for signed-in admins', function () {
    get('/admin/os-advisories')->assertRedirect('/admin/login');
});

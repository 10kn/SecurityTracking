<?php

use App\Models\AccessibleRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

use function Pest\Laravel\from;
use function Pest\Laravel\post;

it('replaces the repository list with the repositories the github app may read now', function () {
    actingAsAdmin();
    $this->travelTo('2026-10-25 09:00:00');
    $kept = AccessibleRepository::factory()->create(['full_name' => 'acme/shop', 'default_branch' => 'master', 'updated_at' => now()->subWeek()]);
    AccessibleRepository::factory()->create(['full_name' => 'acme/revoked']);
    Http::fake(['https://api.github.com/installation/repositories*' => Http::response(['total_count' => 2, 'repositories' => [
        ['full_name' => 'acme/shop', 'default_branch' => 'main', 'private' => true],
        ['full_name' => 'acme/storefront', 'default_branch' => 'develop', 'private' => false],
    ]])]);
    fakeGitHub();

    from('/admin/services/create')->post('/admin/repositories/sync')
        ->assertRedirect('/admin/services/create')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Đã cập nhật danh sách: 2 repository.']);

    expect(AccessibleRepository::orderBy('full_name')->get(['full_name', 'default_branch', 'is_private'])->toArray())->toBe([
        ['full_name' => 'acme/shop', 'default_branch' => 'main', 'is_private' => true],
        ['full_name' => 'acme/storefront', 'default_branch' => 'develop', 'is_private' => false],
    ])->and($kept->fresh()->updated_at)->toEqual(now());
});

it('keeps the list when github cannot be reached', function () {
    actingAsAdmin();
    Sleep::fake();
    AccessibleRepository::factory()->create(['full_name' => 'acme/shop']);
    Http::fake(['https://api.github.com/installation/repositories*' => Http::response('Service Unavailable', 503)]);
    fakeGitHub();

    post('/admin/repositories/sync')
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Không lấy được danh sách repository từ GitHub');

    expect(AccessibleRepository::pluck('full_name')->all())->toBe(['acme/shop']);
});

it('says the github app is not configured', function () {
    actingAsAdmin();
    Http::preventStrayRequests();

    post('/admin/repositories/sync')->assertInertiaFlash('toast.description', 'Chưa cấu hình GitHub App (PSM_GITHUB_APP_ID, PSM_GITHUB_INSTALLATION_ID, PSM_GITHUB_PRIVATE_KEY_PATH).');
});

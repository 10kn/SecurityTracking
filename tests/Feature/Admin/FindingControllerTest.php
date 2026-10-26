<?php

use App\Actions\AcknowledgeFindings;
use App\Enums\FindingEventType;
use App\Enums\FindingState;
use App\Enums\Severity;
use App\Models\Deployment;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->travelTo('2026-10-24 10:00:00');
    $this->commerce = Team::factory()->create(['name' => 'Commerce']);
    $this->deployment = Deployment::factory()->for(Service::factory()->for(Project::factory()->for($this->commerce))->state(['name' => 'shop-api']))->create();
});

it('lists the open findings, Critical first, with their deadline and who read them', function () {
    $admin = actingAsAdmin();
    Finding::factory()->for($this->deployment)->create(['title' => 'PHP 8.1 hết hỗ trợ', 'severity' => Severity::High, 'sla_due_at' => '2026-10-23 10:00:00', 'acknowledged_at' => now(), 'acknowledged_by' => $admin->id]);
    Finding::factory()->for($this->deployment)->create(['title' => 'GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt', 'severity' => Severity::Critical, 'sla_due_at' => '2026-10-27 10:00:00']);
    Finding::factory()->for($this->deployment)->create(['state' => FindingState::Resolved]);

    get('/admin/findings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('findings/index')
            ->has('findings.data', 2)
            ->where('findings.data.0.title', 'GHSA-8xf4-w7qw-pjjw trong firebase/php-jwt')
            ->where('findings.data.0.acknowledged', null)
            ->where('findings.data.0.deadline', '27/10/2026')
            ->where('findings.data.1.is_overdue', true)
            ->where('findings.data.1.acknowledged.by', $admin->name)
            ->where('findings.data.1.service.name', 'shop-api')
            ->where('findings.data.1.team', 'Commerce'));
});

it('filters on a team, the read state and the findings of one card, as the links in Google Chat do', function () {
    actingAsAdmin();
    $booking = Deployment::factory()->for(Service::factory()->for(Project::factory()->for(Team::factory())))->create();
    $inCard = Finding::factory()->for($this->deployment)->create(['title' => 'Unread in card']);
    $readInCard = Finding::factory()->for($this->deployment)->create(['title' => 'Read', 'acknowledged_at' => now()]);
    Finding::factory()->for($this->deployment)->create(['title' => 'Other card']);
    $otherTeam = Finding::factory()->for($booking)->create(['title' => 'Other team']);

    get("/admin/findings?team={$this->commerce->id}&status=unread&ids={$inCard->id},{$readInCard->id},{$otherTeam->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('findings.data', 1)
            ->where('findings.data.0.title', 'Unread in card')
            ->where('table.ids', "{$inCard->id},{$readInCard->id},{$otherTeam->id}"));

    get('/admin/findings?status=read')->assertInertia(fn (Assert $page) => $page->has('findings.data', 1)->where('findings.data.0.title', 'Read'));
});

it('shows times in the digest timezone, as Google Chat does', function () {
    actingAsAdmin();
    Finding::factory()->for($this->deployment)->create(['first_seen_at' => '2026-10-23 18:00:00', 'sla_due_at' => '2026-10-27 20:00:00', 'acknowledged_at' => '2026-10-23 18:30:00']);

    get('/admin/findings')->assertInertia(fn (Assert $page) => $page
        ->where('findings.data.0.first_seen', '24/10/2026')
        ->where('findings.data.0.deadline', '28/10/2026')
        ->where('findings.data.0.acknowledged.at', '24/10/2026 01:30'));
});

/**
 * What the page sends for these findings: each with the version it shows.
 *
 * @return list<array{id: int, version: int|null}>
 */
function shownFindings(Finding ...$findings): array
{
    return array_map(fn (Finding $finding): array => [
        'id' => $finding->id,
        'version' => $finding->events()->whereIn('type', AcknowledgeFindings::VERSION_EVENTS)->max('id'),
    ], $findings);
}

it('marks only the picked findings that are open and not read yet, and records who did', function () {
    $admin = actingAsAdmin();
    $picked = Finding::factory()->for($this->deployment)->create();
    $alreadyRead = Finding::factory()->for($this->deployment)->create(['acknowledged_at' => '2026-10-20 10:00:00']);
    $resolved = Finding::factory()->for($this->deployment)->create(['state' => FindingState::Resolved]);
    $notPicked = Finding::factory()->for($this->deployment)->create();

    post('/admin/findings/acknowledgements', ['findings' => shownFindings($picked, $alreadyRead, $resolved)])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Đã đánh dấu 1 finding là đã đọc. Hệ thống ngừng nhắc hằng ngày.');

    expect($picked->refresh())
        ->acknowledged_at->toDateTimeString()->toBe('2026-10-24 10:00:00')
        ->acknowledged_by->toBe($admin->id)
        ->and($picked->events()->where('type', FindingEventType::Acknowledged)->sole()->data)->toEqual(['user_id' => $admin->id, 'user' => $admin->name])
        ->and($alreadyRead->refresh()->acknowledged_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and($resolved->refresh()->acknowledged_at)->toBeNull()
        ->and($notPicked->refresh()->acknowledged_at)->toBeNull();
});

it('does not mark a finding that rose in severity or opened again since the page was loaded', function (FindingEventType $change) {
    actingAsAdmin();
    $finding = Finding::factory()->for($this->deployment)->create();
    $finding->events()->create(['type' => FindingEventType::Opened]);
    $shown = shownFindings($finding);
    $finding->events()->create(['type' => $change]);

    post('/admin/findings/acknowledgements', ['findings' => $shown])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.description', '1 finding đã thay đổi từ lúc mở trang (mức tăng hoặc mở lại) nên chưa được đánh dấu. Xem lại rồi đánh dấu.');

    expect($finding->refresh()->acknowledged_at)->toBeNull();
})->with([FindingEventType::Escalated, FindingEventType::Opened]);

it('goes back to the first page of the list', function () {
    actingAsAdmin();
    $finding = Finding::factory()->for($this->deployment)->create();

    post('/admin/findings/acknowledgements', ['findings' => shownFindings($finding)], ['referer' => "http://localhost/admin/findings?team={$this->commerce->id}&page=2"])
        ->assertRedirect("http://localhost/admin/findings?team={$this->commerce->id}");
});

it('asks which findings to mark', function () {
    actingAsAdmin();

    post('/admin/findings/acknowledgements', [])->assertSessionHasErrors('findings');
});

it('is only for admins', function () {
    $finding = Finding::factory()->for($this->deployment)->create();

    get('/admin/findings')->assertRedirect('/admin/login');

    actingAs(User::factory()->create());

    post('/admin/findings/acknowledgements', ['findings' => shownFindings($finding)]);
    expect($finding->refresh()->acknowledged_at)->toBeNull();
});

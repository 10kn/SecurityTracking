<?php

use App\Enums\ProjectEventType;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $this->project = Project::factory()->for(Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]))->create(['name' => 'Shop', 'slug' => 'shop']);
});

it('pauses the monitoring of a project with the reason, and records who did', function () {
    $admin = actingAsAdmin();

    post("/admin/projects/{$this->project->id}/monitoring-pause", ['reason' => '  Đã ngừng maintain.  '])
        ->assertRedirect("/admin/projects/{$this->project->id}")
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Đã ngừng theo dõi project Shop.');

    expect($this->project->refresh())
        ->monitoring_paused_by->toBe($admin->id)
        ->monitoring_pause_reason->toBe('Đã ngừng maintain.')
        ->monitoring_paused_at->toDateTimeString()->toBe('2026-10-20 21:00:00')
        ->and(ProjectEvent::sole())->type->toBe(ProjectEventType::Paused)->user_id->toBe($admin->id)->data->toMatchArray(['reason' => 'Đã ngừng maintain.']);

    get("/admin/projects/{$this->project->id}")->assertInertia(fn (Assert $page) => $page
        ->where('project.monitoring.by', $admin->name)
        ->where('project.monitoring.reason', 'Đã ngừng maintain.')
        ->where('history.0.type', 'Ngừng theo dõi')
        ->where('history.0.detail', 'Đã ngừng maintain.'));

    get('/admin/projects')->assertInertia(fn (Assert $page) => $page->where('projects.data.0.is_paused', true));
});

it('asks for the reason of a pause', function () {
    actingAsAdmin();

    post("/admin/projects/{$this->project->id}/monitoring-pause", ['reason' => ''])->assertSessionHasErrors('reason');

    expect($this->project->refresh()->isPaused())->toBeFalse();
});

it('resumes the monitoring with the admin\'s choice and note', function () {
    $admin = actingAsAdmin();
    $this->project->update(['monitoring_paused_at' => now()->subWeek(), 'monitoring_pause_reason' => 'Đã ngừng maintain.']);

    delete("/admin/projects/{$this->project->id}/monitoring-pause", ['notify' => false, 'note' => 'Bàn giao lại cho team.'])
        ->assertRedirect("/admin/projects/{$this->project->id}")
        ->assertInertiaFlash('toast.message', 'Đã theo dõi lại project Shop.');

    expect($this->project->refresh())->monitoring_paused_at->toBeNull()->monitoring_pause_reason->toBeNull()
        ->and(ProjectEvent::sole())->type->toBe(ProjectEventType::Resumed)->user_id->toBe($admin->id)->data->toMatchArray(['notify' => false, 'note' => 'Bàn giao lại cho team.', 'reason' => 'Đã ngừng maintain.']);

    get("/admin/projects/{$this->project->id}")->assertInertia(fn (Assert $page) => $page
        ->where('project.monitoring', null)
        ->where('history.0.detail', 'Không gửi, đánh dấu tồn đọng đã đọc · Bàn giao lại cho team.'));
});

it('asks whether to tell the team when resuming', function () {
    actingAsAdmin();
    $this->project->update(['monitoring_paused_at' => now()]);

    delete("/admin/projects/{$this->project->id}/monitoring-pause", [])->assertSessionHasErrors('notify');

    expect($this->project->refresh()->isPaused())->toBeTrue();
});

it('keeps the team of a paused project until it is resumed', function () {
    actingAsAdmin();
    $this->project->update(['monitoring_paused_at' => now()]);
    $booking = Team::factory()->create();

    put("/admin/projects/{$this->project->id}", ['team_id' => $booking->id, 'name' => 'Shop', 'slug' => 'shop'])->assertSessionHasErrors(['team_id' => 'Mở lại project trước khi đổi team.']);
    put("/admin/projects/{$this->project->id}", ['team_id' => $this->project->team_id, 'name' => 'Shop 2', 'slug' => 'shop'])->assertSessionHasNoErrors();

    expect($this->project->refresh()->name)->toBe('Shop 2');
});

it('only lets admins pause or resume a project', function () {
    actingAs(User::factory()->create());

    post("/admin/projects/{$this->project->id}/monitoring-pause", ['reason' => 'x'])->assertForbidden();
    delete("/admin/projects/{$this->project->id}/monitoring-pause", ['notify' => true])->assertForbidden();
});

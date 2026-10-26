<?php

use App\Actions\AssignNoticeToIncident;
use App\Actions\MergeIncidents;
use App\Actions\SendPreNoticeAlerts;
use App\Enums\ChatNotificationKind;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSuggestionReason;
use App\Enums\IncidentSuggestionStatus;
use App\Enums\IncidentTeamEventType;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamEvent;
use App\Models\Runtime;
use App\Models\RuntimeRelease;
use App\Models\Team;
use App\Models\User;
use App\Services\IncidentTeamExposure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $this->commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->booking = Team::factory()->create(['name' => 'Booking', 'google_chat_webhook_url' => chatWebhook('BOOKING')]);
    $this->preNotice = Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'Upcoming Critical PHP Security Release',
        'severity' => Severity::High,
        'aliases' => ['CVE-2026-1001'],
        'url' => 'https://www.php.net/archive/2026.php#2026-10-20-1',
        'published_at' => '2026-10-20 20:32:00',
    ]);
    pageRuntime($this->commerce, 'shop-api', '8.3.35');
    pageRuntime($this->booking, 'booking-api', '8.4.26');
    app(SendPreNoticeAlerts::class)->handle();
    $this->incident = $this->preNotice->refresh()->incident;
});

/**
 * A PHP runtime of the team's service on a known branch.
 */
function pageRuntime(Team $team, string $service, string $version): Runtime
{
    $runtime = runtimeAt($team, $service, Technology::Php, $version);
    $runtime->update(['version' => $version, 'branch' => implode('.', array_slice(explode('.', $version), 0, 2))]);

    return $runtime;
}

/**
 * What the page sends to mark the incident as read for a team: its impact and note with the exposure hash and the
 * assessment revision it shows.
 *
 * @return array{id: int, impact: string, note: string|null, exposure_hash: string, revision: int}
 */
function shownAssessment(Incident $incident, Team $team, IncidentImpact $impact, ?string $note = null): array
{
    return [
        'id' => $team->id,
        'impact' => $impact->value,
        'note' => $note,
        'exposure_hash' => IncidentTeamExposure::for($incident->refresh(), $team)->hash(),
        'revision' => (int) IncidentTeam::where('incident_id', $incident->id)->where('team_id', $team->id)->value('assessment_revision'),
    ];
}

function rowOf(Incident $incident, Team $team): IncidentTeam
{
    return IncidentTeam::where('incident_id', $incident->id)->where('team_id', $team->id)->sole();
}

it('lists current incidents with their lead notice, fixes, told teams and what needs an admin', function () {
    actingAsAdmin();
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    Incident::factory()->create(['merged_into_id' => $this->incident->id, 'title' => 'Merged away']);
    IncidentSuggestion::factory()->create(['incident_id' => $this->incident->id, 'reason' => IncidentSuggestionReason::Classifier]);

    get('/admin/incidents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('incidents/index')
            ->has('incidents.data', 1)
            ->where('incidents.data.0.lead', 'Upcoming Critical PHP Security Release')
            ->where('incidents.data.0.severity.value', 'high')
            ->where('incidents.data.0.technology.label', 'PHP')
            ->where('incidents.data.0.notices_count', 1)
            ->where('incidents.data.0.teams_told_count', 2)
            ->where('incidents.data.0.fixes', [['branch' => '8.3', 'version' => '8.3.36'], ['branch' => '8.4', 'version' => null]])
            ->where('incidents.data.0.fix_status.label', 'Có bản vá một phần')
            ->where('incidents.data.0.pending_suggestions_count', 1)
            ->where('incidents.data.0.teams_to_acknowledge_count', 1));
});

it('filters on a technology, a team told and what needs an admin', function () {
    actingAsAdmin();
    $node = Incident::factory()->create(['technology' => Technology::Nodejs, 'title' => 'Node.js security releases', 'first_published_at' => now()]);
    Incident::factory()->create(['title' => 'Old PHP incident', 'first_published_at' => now()->subDays(90)]);

    get('/admin/incidents?technology=nodejs')->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('incidents.data.0.id', $node->id)->where('table.technology', 'nodejs'));
    get("/admin/incidents?team={$this->commerce->id}")->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('incidents.data.0.id', $this->incident->id));
    get('/admin/incidents?attention=1')->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('incidents.data.0.id', $this->incident->id)->where('table.attention', 1));

    assessIncident($this->incident, $this->commerce, IncidentImpact::Yes);
    assessIncident($this->incident, $this->booking, IncidentImpact::No);

    get('/admin/incidents?attention=1')->assertInertia(fn (Assert $page) => $page->has('incidents.data', 0));
});

it('filters on what needs an admin for the filtered team only', function () {
    actingAsAdmin();
    assessIncident($this->incident, $this->commerce, IncidentImpact::Yes);

    get("/admin/incidents?team={$this->commerce->id}&attention=1")->assertInertia(fn (Assert $page) => $page->has('incidents.data', 0));
    get("/admin/incidents?team={$this->booking->id}&attention=1")->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1));
});

it('counts a told team whose service runs an unknown branch as needing an admin', function () {
    actingAsAdmin();
    assessIncident($this->incident, $this->booking, IncidentImpact::Yes);
    Runtime::whereHas('deployment.service', fn ($query) => $query->where('name', 'shop-api'))->update(['branch' => null, 'version' => null]);

    get('/admin/incidents?attention=1')->assertInertia(fn (Assert $page) => $page->has('incidents.data', 1)->where('incidents.data.0.teams_to_acknowledge_count', 1));
});

/**
 * The number of queries a request runs.
 */
function queryCountOf(Closure $request): int
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    $request();

    return $count;
}

it('reads the list in a number of queries that does not grow with the incidents or the teams', function (string $url) {
    actingAsAdmin();
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    IncidentSuggestion::factory()->create(['incident_id' => $this->incident->id, 'reason' => IncidentSuggestionReason::Classifier]);
    $before = queryCountOf(fn () => get($url)->assertOk());

    foreach (['ops-api', 'ops-web'] as $service) {
        pageRuntime(Team::factory()->create(['google_chat_webhook_url' => chatWebhook(strtoupper($service))]), $service, '8.2.20');
    }

    foreach (range(2, 4) as $index) {
        Advisory::factory()->preNotice(Technology::Php)->create(['title' => "PHP issue {$index}", 'severity' => Severity::High, 'aliases' => ["CVE-2026-500{$index}"], 'published_at' => now(), 'patch_versions' => ['8.3.36']]);
    }

    app(SendPreNoticeAlerts::class)->handle();
    IncidentSuggestion::factory()->create(['incident_id' => Incident::current()->latest('id')->value('id'), 'reason' => IncidentSuggestionReason::Classifier]);
    expect(Incident::current()->count())->toBe(5);

    expect(queryCountOf(fn () => get($url)->assertOk()))->toBe($before);
})->with(['/admin/incidents', '/admin/incidents?attention=1']);

it('shows the incident with its notices, its teams and what the forms need', function () {
    $admin = actingAsAdmin();
    assessIncident($this->incident, $this->booking, IncidentImpact::No, $admin, 'Chỉ chạy CLI nội bộ');
    $retired = Team::factory()->create(['name' => 'Retired']);
    ChatNotification::factory()->for($retired)->sent()->create(['kind' => ChatNotificationKind::PreNotice, 'dedupe_key' => "pre_notice:{$this->preNotice->id}:{$retired->id}:high"]);

    get("/admin/incidents/{$this->incident->id}?team={$this->commerce->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('incidents/show')
            ->where('incident.id', $this->incident->id)
            ->has('notices', 1)
            ->where('notices.0.title', 'Upcoming Critical PHP Security Release')
            ->where('focusTeam', $this->commerce->id)
            ->has('teams', 3)
            ->where('teams.0.name', 'Booking')
            ->where('teams.0.assessment.impact.value', 'no')
            ->where('teams.0.assessment.note', 'Chỉ chạy CLI nội bộ')
            ->where('teams.0.assessment.is_valid', true)
            ->where('teams.0.revision', 1)
            ->where('teams.0.history.0.type', 'Đã đọc')
            ->where('teams.1.name', 'Commerce')
            ->where('teams.1.is_actionable', true)
            ->where('teams.1.needs_acknowledgement', true)
            ->where('teams.1.told_severity.value', 'high')
            ->where('teams.1.services.0', ['name' => 'shop-api', 'branch' => '8.3', 'scope' => 'chưa xác định', 'is_not_affected' => false, 'patch' => null])
            ->where('teams.1.thread.status.label', 'Đã mở thread')
            ->where('teams.1.thread.url', fn (string $url): bool => str_starts_with($url, 'https://chat.google.com/room/COMMERCE/'))
            ->where('teams.1.exposure_hash', IncidentTeamExposure::for($this->incident, $this->commerce)->hash())
            ->where('teams.2.name', 'Retired')
            ->where('teams.2.is_told', true)
            ->where('teams.2.is_actionable', false)
            ->where('update.recipients', [
                ['value' => $this->booking->id, 'label' => 'Booking', 'is_default' => true],
                ['value' => $this->commerce->id, 'label' => 'Commerce', 'is_default' => true],
                ['value' => $retired->id, 'label' => 'Retired', 'is_default' => false],
            ])
            ->where('update.request_id', fn (string $id): bool => Str::isUuid($id)));
});

it('opens the incident a merged one went into', function () {
    actingAsAdmin();
    $merged = Incident::factory()->create(['merged_into_id' => $this->incident->id]);

    get("/admin/incidents/{$merged->id}")
        ->assertRedirect("/admin/incidents/{$this->incident->id}")
        ->assertInertiaFlash('toast.message', "Sự kiện #{$merged->id} đã được gộp vào sự kiện này.");
});

it('marks the incident as read for a team with its impact and note, and records who did', function () {
    $admin = actingAsAdmin();

    post("/admin/incidents/{$this->incident->id}/acknowledgements", ['teams' => [shownAssessment($this->incident, $this->commerce, IncidentImpact::No, 'Chỉ dùng PHP cho cron')]])
        ->assertRedirect("/admin/incidents/{$this->incident->id}")
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Đã đánh dấu đã đọc cho 1 team. Hệ thống ngừng nhắc hằng ngày.');

    expect(rowOf($this->incident, $this->commerce))
        ->impact->toBe(IncidentImpact::No)
        ->impact_note->toBe('Chỉ dùng PHP cho cron')
        ->acknowledged_by->toBe($admin->id)
        ->acknowledged_at->toDateTimeString()->toBe('2026-10-20 21:00:00')
        ->acknowledged_exposure_hash->toBe(IncidentTeamExposure::for($this->incident, $this->commerce)->hash())
        ->assessment_revision->toBe(1)
        ->and(IncidentTeamEvent::sole())
        ->type->toBe(IncidentTeamEventType::Acknowledged)
        ->user_id->toBe($admin->id)
        ->data->toMatchArray(['impact' => 'no', 'note' => 'Chỉ dùng PHP cho cron', 'previous_impact' => null]);
});

it('marks the selected teams together, each with what it shows', function () {
    actingAsAdmin();

    post("/admin/incidents/{$this->incident->id}/acknowledgements", ['teams' => [
        shownAssessment($this->incident, $this->commerce, IncidentImpact::Yes),
        shownAssessment($this->incident, $this->booking, IncidentImpact::Unknown),
    ]])->assertInertiaFlash('toast.message', 'Đã đánh dấu đã đọc cho 2 team. Hệ thống ngừng nhắc hằng ngày.');

    expect(rowOf($this->incident, $this->commerce)->impact)->toBe(IncidentImpact::Yes)
        ->and(rowOf($this->incident, $this->booking)->impact)->toBe(IncidentImpact::Unknown);
});

it('does not save a team whose exposure changed since the page was loaded', function () {
    actingAsAdmin();
    $shown = shownAssessment($this->incident, $this->commerce, IncidentImpact::No);
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);

    post("/admin/incidents/{$this->incident->id}/acknowledgements", ['teams' => [$shown]])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.description', '1 team đã thay đổi từ lúc mở trang (tin mới, phạm vi, mức độ hoặc bản vá) nên chưa được lưu. Xem lại rồi đánh dấu.');

    expect(rowOf($this->incident, $this->commerce)->impact)->toBeNull();
});

it('does not overwrite what another admin saved meanwhile, and lets an admin correct an assessment', function () {
    actingAsAdmin();
    $shown = shownAssessment($this->incident, $this->commerce, IncidentImpact::No);
    $other = assessIncident($this->incident, $this->commerce, IncidentImpact::Yes);

    post("/admin/incidents/{$this->incident->id}/acknowledgements", ['teams' => [$shown]])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.description', '1 team vừa được admin khác đánh giá nên chưa được lưu. Xem đánh giá mới rồi sửa nếu cần.');

    expect($other->refresh()->impact)->toBe(IncidentImpact::Yes);

    post("/admin/incidents/{$this->incident->id}/acknowledgements", ['teams' => [shownAssessment($this->incident, $this->commerce, IncidentImpact::No)]])
        ->assertInertiaFlash('toast.type', 'success');

    expect($other->refresh())->impact->toBe(IncidentImpact::No)->assessment_revision->toBe(2)
        ->and(IncidentTeamEvent::where('type', IncidentTeamEventType::Acknowledged)->orderBy('id')->pluck('data')->map(fn (array $data): array => [$data['previous_impact'], $data['impact']])->all())
        ->toBe([[null, 'yes'], ['yes', 'no']]);
});

it('records the reset of an assessment that stopped holding when an admin saves the team again', function () {
    actingAsAdmin();
    assessIncident($this->incident, $this->commerce, IncidentImpact::No);
    $this->preNotice->update(['severity' => Severity::Critical]);
    $shown = shownAssessment($this->incident, $this->commerce, IncidentImpact::Yes);

    post("/admin/incidents/{$this->incident->id}/acknowledgements", ['teams' => [$shown]])->assertInertiaFlash('toast.type', 'success');

    expect(rowOf($this->incident, $this->commerce))->impact->toBe(IncidentImpact::Yes)->assessment_revision->toBe(3)
        ->and(IncidentTeamEvent::orderBy('id')->pluck('type')->all())->toBe([IncidentTeamEventType::Acknowledged, IncidentTeamEventType::ImpactReset, IncidentTeamEventType::Acknowledged]);
});

it('only assesses teams told about the incident that still run its technology', function () {
    actingAsAdmin();
    $untold = Team::factory()->create();
    pageRuntime($untold, 'untold-api', '8.3.30');

    post("/admin/incidents/{$this->incident->id}/acknowledgements", ['teams' => [shownAssessment($this->incident, $untold, IncidentImpact::No)]])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.description', '1 team chưa được báo về sự kiện hoặc không còn chạy công nghệ này nên không đánh giá được.');

    expect(IncidentTeam::where('team_id', $untold->id)->value('impact'))->toBeNull();
});

it('asks for the impact of each team', function () {
    actingAsAdmin();

    post("/admin/incidents/{$this->incident->id}/acknowledgements", ['teams' => [[...shownAssessment($this->incident, $this->commerce, IncidentImpact::No), 'impact' => 'maybe']]])
        ->assertSessionHasErrors('teams.0.impact');
});

it('accepts a suggestion for a pre-notice alone in its incident by merging that incident', function () {
    $admin = actingAsAdmin();
    $notice = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP 8.4 notice', 'aliases' => ['CVE-2026-3003'], 'published_at' => '2026-10-20 20:00:00']);
    app(AssignNoticeToIncident::class)->assignUnassigned();
    $source = $notice->refresh()->incident;
    $suggestion = IncidentSuggestion::factory()->create(['advisory_id' => $notice->id, 'incident_id' => $this->incident->id, 'reason' => IncidentSuggestionReason::Classifier]);

    post("/admin/incident-suggestions/{$suggestion->id}/decision", ['decision' => 'accept', 'incident_id' => $source->id, 'target_id' => $this->incident->id])
        ->assertRedirect("/admin/incidents/{$this->incident->id}")
        ->assertInertiaFlash('toast.type', 'success');

    expect($notice->refresh()->incident_id)->toBe($this->incident->id)
        ->and($source->refresh()->merged_into_id)->toBe($this->incident->id)
        ->and($suggestion->refresh())->status->toBe(IncidentSuggestionStatus::Accepted)->decided_by->toBe($admin->id);
});

it('accepts a suggestion for a pre-notice sharing its incident by moving only that pre-notice', function () {
    actingAsAdmin();
    $notice = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP 8.4 notice', 'aliases' => ['CVE-2026-3003'], 'published_at' => '2026-10-20 20:00:00']);
    $sibling = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP 8.4 follow-up', 'aliases' => ['CVE-2026-3003'], 'published_at' => '2026-10-20 20:10:00']);
    app(AssignNoticeToIncident::class)->assignUnassigned();
    $source = $notice->refresh()->incident;
    expect($sibling->refresh()->incident_id)->toBe($source->id);
    $suggestion = IncidentSuggestion::factory()->create(['advisory_id' => $notice->id, 'incident_id' => $this->incident->id, 'reason' => IncidentSuggestionReason::Classifier]);

    post("/admin/incident-suggestions/{$suggestion->id}/decision", ['decision' => 'accept', 'incident_id' => $source->id, 'target_id' => $this->incident->id])
        ->assertRedirect("/admin/incidents/{$this->incident->id}");

    expect($notice->refresh()->incident_id)->toBe($this->incident->id)
        ->and($sibling->refresh()->incident_id)->toBe($source->id)
        ->and($source->refresh()->merged_into_id)->toBeNull()
        ->and($suggestion->refresh()->status)->toBe(IncidentSuggestionStatus::Accepted)
        ->and(Incident::whereNotNull('merged_into_id')->sole()->merged_into_id)->toBe($this->incident->id);
});

it('does not accept a suggestion that was decided or whose pre-notice moved since the page was loaded', function (string $change) {
    actingAsAdmin();
    $notice = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP 8.4 notice', 'aliases' => ['CVE-2026-3003'], 'published_at' => '2026-10-20 20:00:00']);
    app(AssignNoticeToIncident::class)->assignUnassigned();
    $source = $notice->refresh()->incident;
    $suggestion = IncidentSuggestion::factory()->create(['advisory_id' => $notice->id, 'incident_id' => $this->incident->id, 'reason' => IncidentSuggestionReason::Classifier]);
    $change === 'decided' ? $suggestion->update(['status' => IncidentSuggestionStatus::Rejected]) : $this->incident->update(['merged_into_id' => $source->id]);

    post("/admin/incident-suggestions/{$suggestion->id}/decision", ['decision' => 'accept', 'incident_id' => $source->id, 'target_id' => $this->incident->id])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Gợi ý, tin hoặc sự kiện đã thay đổi từ lúc mở trang nên chưa gộp. Xem lại rồi thử lại.');

    expect($notice->refresh()->incident_id)->toBe($source->id)
        ->and($source->refresh()->merged_into_id)->toBeNull();
})->with(['decided', 'target merged']);

it('does not follow a suggestion moved to another incident since the page was loaded', function () {
    $admin = actingAsAdmin();
    $notice = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP 8.4 notice', 'aliases' => ['CVE-2026-3003'], 'published_at' => '2026-10-20 20:00:00']);
    $later = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Another PHP issue', 'aliases' => ['CVE-2026-4004'], 'published_at' => '2026-10-20 20:10:00']);
    app(AssignNoticeToIncident::class)->assignUnassigned();
    $source = $notice->refresh()->incident;
    $suggestion = IncidentSuggestion::factory()->create(['advisory_id' => $notice->id, 'incident_id' => $this->incident->id, 'reason' => IncidentSuggestionReason::Classifier]);
    app(MergeIncidents::class)->handle($this->incident, $later->refresh()->incident, $admin);
    expect($suggestion->refresh()->incident_id)->toBe($later->incident_id);

    post("/admin/incident-suggestions/{$suggestion->id}/decision", ['decision' => 'accept', 'incident_id' => $source->id, 'target_id' => $this->incident->id])
        ->assertInertiaFlash('toast.type', 'error');

    expect($notice->refresh()->incident_id)->toBe($source->id)
        ->and($suggestion->refresh()->status)->toBe(IncidentSuggestionStatus::Pending);
});

it('rejects only the chosen suggestion', function () {
    $admin = actingAsAdmin();
    [$rejected, $kept] = IncidentSuggestion::factory()->count(2)->create(['incident_id' => $this->incident->id]);

    post("/admin/incident-suggestions/{$rejected->id}/decision", ['decision' => 'reject'])->assertInertiaFlash('toast.message', 'Đã bỏ qua gợi ý.');

    expect($rejected->refresh())->status->toBe(IncidentSuggestionStatus::Rejected)->decided_by->toBe($admin->id)->decided_at->not->toBeNull()
        ->and($kept->refresh()->status)->toBe(IncidentSuggestionStatus::Pending);

    post("/admin/incident-suggestions/{$rejected->id}/decision", ['decision' => 'reject'])->assertInertiaFlash('toast.type', 'error');
});

it('merges the incident into another one from the page', function () {
    actingAsAdmin();
    $other = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP 8.4 notice', 'aliases' => ['CVE-2026-3003'], 'published_at' => '2026-10-20 20:00:00']);
    app(AssignNoticeToIncident::class)->assignUnassigned();
    $from = $other->refresh()->incident;

    post("/admin/incidents/{$from->id}/merge", ['into_id' => $this->incident->id])
        ->assertRedirect("/admin/incidents/{$this->incident->id}")
        ->assertInertiaFlash('toast.message', "Đã gộp sự kiện #{$from->id} vào sự kiện này.");

    expect($from->refresh()->merged_into_id)->toBe($this->incident->id)
        ->and($other->refresh()->incident_id)->toBe($this->incident->id);

    post("/admin/incidents/{$from->id}/merge", ['into_id' => $this->incident->id])->assertInertiaFlash('toast.type', 'error');
});

it('splits a pre-notice off into a new incident from the page, only from the incident it was shown in', function () {
    actingAsAdmin();
    $sibling = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP security advisory', 'aliases' => ['CVE-2026-1001'], 'published_at' => '2026-10-20 20:40:00']);
    app(AssignNoticeToIncident::class)->assignUnassigned();
    expect($sibling->refresh()->incident_id)->toBe($this->incident->id);

    post("/admin/notices/{$sibling->id}/split", ['incident_id' => $this->incident->id + 1000])->assertInertiaFlash('toast.type', 'error');
    expect($sibling->refresh()->incident_id)->toBe($this->incident->id);

    post("/admin/notices/{$sibling->id}/split", ['incident_id' => $this->incident->id])->assertInertiaFlash('toast.type', 'success');

    $split = $sibling->refresh()->incident;
    expect($split->id)->not->toBe($this->incident->id)
        ->and(IncidentTeam::where('incident_id', $split->id)->whereNotNull('impact')->exists())->toBeFalse();
});

it('sends an urgent update to the confirmed teams once per request, records it and marks nothing as read', function () {
    $admin = actingAsAdmin();
    $requestId = (string) Str::uuid();
    $form = ['message' => 'Tắt opcache.preload cho tới khi có bản vá.', 'team_ids' => [$this->commerce->id], 'request_id' => $requestId];

    post("/admin/incidents/{$this->incident->id}/updates", $form)
        ->assertInertiaFlash('toast.message', 'Đã gửi cập nhật tới 1 team.')
        ->assertInertiaFlash('incidentUpdate', ['request_id' => $requestId, 'status' => 'queued']);
    post("/admin/incidents/{$this->incident->id}/updates", $form)
        ->assertInertiaFlash('toast.message', 'Cập nhật này đã được gửi trước đó, không gửi lại.')
        ->assertInertiaFlash('incidentUpdate', ['request_id' => $requestId, 'status' => 'duplicate']);

    $notification = ChatNotification::where('kind', ChatNotificationKind::IncidentUpdate)->sole();
    expect($notification)->team_id->toBe($this->commerce->id)->requested_by->toBe("user:{$admin->id}")
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Tắt opcache.preload cho tới khi có bản vá.')
        ->and(chatMessagesTo('BOOKING'))->toHaveCount(1)
        ->and(IncidentTeamEvent::sole())
        ->type->toBe(IncidentTeamEventType::UpdateSent)
        ->user_id->toBe($admin->id)
        ->data->toMatchArray(['request_id' => $requestId, 'chat_notification_id' => $notification->id])
        ->and(rowOf($this->incident, $this->commerce)->impact)->toBeNull();
});

it('sends a second update with the fresh request id of the reloaded page', function () {
    actingAsAdmin();
    $requestIds = [];

    foreach (['Bản cập nhật thứ nhất.', 'Bản cập nhật thứ hai.'] as $message) {
        get("/admin/incidents/{$this->incident->id}")->assertInertia(function (Assert $page) use (&$requestIds) {
            $requestIds[] = $page->toArray()['props']['update']['request_id'];

            return $page;
        });

        post("/admin/incidents/{$this->incident->id}/updates", ['message' => $message, 'team_ids' => [$this->commerce->id], 'request_id' => end($requestIds)])
            ->assertInertiaFlash('toast.message', 'Đã gửi cập nhật tới 1 team.');
    }

    expect($requestIds[0])->not->toBe($requestIds[1])
        ->and(ChatNotification::where('kind', ChatNotificationKind::IncidentUpdate)->count())->toBe(2)
        ->and(chatJson(chatMessagesTo('COMMERCE')->take(-2)->values()))->toContain('Bản cập nhật thứ nhất.', 'Bản cập nhật thứ hai.');
});

it('does not confirm an update it could not send, so the form keeps it for a retry', function () {
    actingAsAdmin();
    $form = ['message' => 'Hello', 'team_ids' => [$this->commerce->id], 'request_id' => (string) Str::uuid()];

    post("/admin/incidents/{$this->incident->id}/updates", [...$form, 'team_ids' => [Team::factory()->create()->id]])
        ->assertSessionHasErrors('team_ids.0')
        ->assertInertiaFlashMissing('incidentUpdate');

    $this->incident->update(['merged_into_id' => Incident::factory()->create()->id]);

    post("/admin/incidents/{$this->incident->id}/updates", $form)
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlashMissing('incidentUpdate');
});

it('sends an urgent update only to teams told about the incident', function () {
    actingAsAdmin();
    $untold = Team::factory()->create();

    post("/admin/incidents/{$this->incident->id}/updates", ['message' => 'Hello', 'team_ids' => [$untold->id], 'request_id' => (string) Str::uuid()])
        ->assertSessionHasErrors('team_ids.0');

    expect(ChatNotification::where('kind', ChatNotificationKind::IncidentUpdate)->exists())->toBeFalse();
});

it('is only for admins', function () {
    $suggestion = IncidentSuggestion::factory()->create(['incident_id' => $this->incident->id]);

    get('/admin/incidents')->assertRedirect('/admin/login');

    actingAs(User::factory()->create());

    get('/admin/incidents')->assertForbidden();
    get("/admin/incidents/{$this->incident->id}")->assertForbidden();
    post("/admin/incidents/{$this->incident->id}/acknowledgements", ['teams' => [shownAssessment($this->incident, $this->commerce, IncidentImpact::No)]])->assertForbidden();
    post("/admin/incidents/{$this->incident->id}/merge", ['into_id' => $this->incident->id])->assertForbidden();
    post("/admin/incidents/{$this->incident->id}/updates", ['message' => 'Hello', 'team_ids' => [$this->commerce->id], 'request_id' => (string) Str::uuid()])->assertForbidden();
    post("/admin/incident-suggestions/{$suggestion->id}/decision", ['decision' => 'reject'])->assertForbidden();
    post("/admin/notices/{$this->preNotice->id}/split", ['incident_id' => $this->incident->id])->assertForbidden();

    expect(rowOf($this->incident, $this->commerce)->impact)->toBeNull()
        ->and($suggestion->refresh()->status)->toBe(IncidentSuggestionStatus::Pending);
});

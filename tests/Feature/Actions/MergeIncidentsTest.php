<?php

use App\Actions\AssignNoticeToIncident;
use App\Actions\MergeIncidents;
use App\Actions\SendPreNoticeAlerts;
use App\Actions\SplitNotice;
use App\Enums\IncidentBasis;
use App\Enums\IncidentSuggestionReason;
use App\Enums\IncidentSuggestionStatus;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Models\IncidentTeam;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->travelTo('2026-10-21 12:00:00');
    fakeGoogleChat();
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->team = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    runtimeAt($this->team, 'shop-api', Technology::Php, '8.3.35');
    $notice = function (string $title, Severity $severity, array $aliases): Advisory {
        $notice = Advisory::factory()->preNotice(Technology::Php)->create(['title' => $title, 'severity' => $severity, 'aliases' => $aliases]);
        app(AssignNoticeToIncident::class)->handle($notice);

        return $notice->refresh();
    };
    $this->announcement = $notice('Upcoming Critical PHP Security Release', Severity::Critical, []);
    $this->advisory = $notice('PHP security advisory', Severity::Critical, ['CVE-2026-1001']);
});

it('moves the pre-notices of an incident into another and tells no team again what it already heard', function () {
    app(SendPreNoticeAlerts::class)->handle();
    $suggestion = IncidentSuggestion::factory()->create(['advisory_id' => $this->advisory->id, 'incident_id' => $this->announcement->incident_id]);
    $from = $this->advisory->incident;

    $into = app(MergeIncidents::class)->handle($from, $this->announcement->incident, $this->admin);

    expect($this->advisory->refresh())
        ->incident_id->toBe($into->id)
        ->incident_basis->toBe(IncidentBasis::Manual)
        ->and($from->refresh()->merged_into_id)->toBe($into->id)
        ->and($suggestion->refresh())->status->toBe(IncidentSuggestionStatus::Accepted)->decided_by->toBe($this->admin->id)
        ->and(app(SendPreNoticeAlerts::class)->handle())->toBe(0)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(2);
});

it('carries what a team was told about the CVE ids CISA KEV lists over to the incident it merges into', function () {
    $this->advisory->update(['known_exploited' => [['cve' => 'CVE-2026-1001', 'name' => 'PHP RCE', 'date_added' => '2026-10-21', 'due_date' => '2026-10-24', 'listed' => true]]]);
    app(SendPreNoticeAlerts::class)->handle();

    expect(chatJson(chatMessagesTo('COMMERCE')->firstWhere('text', '🔴 [Critical] PHP security advisory')))->toContain('CISA KEV ghi nhận CVE-2026-1001');

    $into = app(MergeIncidents::class)->handle($this->advisory->incident, $this->announcement->incident, $this->admin);

    expect(IncidentTeam::where('incident_id', $into->id)->sole()->told_known_exploited)->toBe(['CVE-2026-1001'])
        ->and(app(SendPreNoticeAlerts::class)->handle())->toBe(0)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(2);
});

it('sends the merged incident once when its pre-notices were never sent', function () {
    app(MergeIncidents::class)->handle($this->advisory->incident, $this->announcement->incident, $this->admin);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->sole()))->toContain('Tin khác của cùng sự kiện', 'PHP security advisory');
});

it('points suggestions for the merged incident at the one it was merged into', function () {
    $other = Advisory::factory()->preNotice(Technology::Php)->create(['aliases' => ['CVE-2026-2002']]);
    app(AssignNoticeToIncident::class)->handle($other);
    $suggestion = IncidentSuggestion::factory()->create(['advisory_id' => $other->id, 'incident_id' => $this->advisory->incident_id, 'reason' => IncidentSuggestionReason::Classifier]);

    $into = app(MergeIncidents::class)->handle($this->advisory->incident, $this->announcement->incident, $this->admin);

    expect($suggestion->refresh())->incident_id->toBe($into->id)->status->toBe(IncidentSuggestionStatus::Pending);
});

it('refuses to merge an incident into itself, into another technology or into a merged incident', function (Incident $into) {
    app(MergeIncidents::class)->handle($this->advisory->incident, $into, $this->admin);
})->with([
    'itself' => fn () => $this->advisory->incident,
    'another technology' => fn () => Incident::factory()->create(['technology' => Technology::Nodejs]),
    'a merged incident' => fn () => Incident::factory()->create(['merged_into_id' => $this->announcement->incident_id]),
])->throws(InvalidArgumentException::class);

it('splits a pre-notice into a new incident, which a team that never had it in a card gets as news', function () {
    app(MergeIncidents::class)->handle($this->advisory->incident, $this->announcement->incident, $this->admin);
    $this->advisory->update(['severity' => Severity::Medium]);
    app(SendPreNoticeAlerts::class)->handle();
    $this->advisory->update(['severity' => Severity::Critical]);

    $incident = app(SplitNotice::class)->handle($this->advisory->refresh(), $this->admin);

    expect($this->advisory->refresh())
        ->incident_id->toBe($incident->id)
        ->incident_basis->toBe(IncidentBasis::Manual)
        ->and(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->last()['text'])->toBe('🔴 [Critical] PHP security advisory');
});

it('does not tell a team again about a pre-notice split off after it was in a card', function () {
    app(MergeIncidents::class)->handle($this->advisory->incident, $this->announcement->incident, $this->admin);
    app(SendPreNoticeAlerts::class)->handle();

    app(SplitNotice::class)->handle($this->advisory->refresh(), $this->admin);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0);
});

it('refuses to split a pre-notice that is alone in its incident', function () {
    app(SplitNotice::class)->handle($this->advisory, $this->admin);
})->throws(InvalidArgumentException::class);

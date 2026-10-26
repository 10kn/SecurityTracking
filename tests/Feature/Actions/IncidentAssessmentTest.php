<?php

use App\Actions\AssignNoticeToIncident;
use App\Actions\MergeIncidents;
use App\Actions\SendDailyDigest;
use App\Actions\SendFindingAlerts;
use App\Actions\SendPreNoticeAlerts;
use App\Actions\SplitNotice;
use App\Enums\FindingRule;
use App\Enums\IncidentImpact;
use App\Enums\IncidentTeamEventType;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\Finding;
use App\Models\Incident;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamEvent;
use App\Models\IncidentTeamNotice;
use App\Models\IncidentTeamPatch;
use App\Models\Runtime;
use App\Models\RuntimeRelease;
use App\Models\Team;
use App\Models\User;
use App\Services\IncidentTeamExposure;

beforeEach(function () {
    $this->travelTo('2026-10-20 21:00:00');
    fakeGoogleChat();
    $this->commerce = Team::factory()->create(['name' => 'Commerce', 'google_chat_webhook_url' => chatWebhook('COMMERCE')]);
    $this->preNotice = Advisory::factory()->preNotice(Technology::Php)->create([
        'title' => 'Upcoming Critical PHP Security Release',
        'severity' => Severity::High,
        'aliases' => ['CVE-2026-1001'],
        'published_at' => '2026-10-20 20:32:00',
    ]);
    $this->runtime = assessedRuntime($this->commerce, 'shop-api', '8.3.35');
});

/**
 * A PHP runtime of the team's service on a known branch.
 */
function assessedRuntime(Team $team, string $service, string $version): Runtime
{
    $runtime = runtimeAt($team, $service, Technology::Php, $version);
    $runtime->update(['version' => $version, 'branch' => implode('.', array_slice(explode('.', $version), 0, 2))]);

    return $runtime;
}

/**
 * The incident of the pre-notice, once the team got its card.
 */
function toldIncident(Advisory $notice): Incident
{
    app(SendPreNoticeAlerts::class)->handle();

    return $notice->refresh()->incident;
}

function holdsFor(Incident $incident, Team $team): bool
{
    return IncidentTeam::where('incident_id', $incident->id)->where('team_id', $team->id)->sole()->hasValidAssessment(IncidentTeamExposure::for($incident->refresh(), $team));
}

it('keeps an assessment while nothing new is asked of the team, also once the vendor confirms the fix for its branch', function () {
    $incident = toldIncident($this->preNotice);
    assessIncident($incident, $this->commerce, IncidentImpact::No);
    $before = IncidentTeamExposure::for($incident, $this->commerce)->hash();

    $this->preNotice->update(['patch_versions' => ['8.3.36'], 'localized_summary' => 'Sửa lại câu chữ.']);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    assessedRuntime($this->commerce, 'shop-legacy', '8.2.20');
    $this->preNotice->update(['unaffected_branches' => ['8.2']]);

    expect(IncidentTeamExposure::for($incident->refresh(), $this->commerce)->hash())->not->toBe($before)
        ->and(holdsFor($incident, $this->commerce))->toBeTrue()
        ->and(IncidentTeam::isRuledOutFor($incident, $this->commerce->id))->toBeTrue();
});

it('ends an assessment once the team\'s exposure grows', function (Closure $change) {
    $incident = toldIncident($this->preNotice);
    assessIncident($incident, $this->commerce, IncidentImpact::No);

    $change->call($this);

    expect(holdsFor($incident, $this->commerce))->toBeFalse()
        ->and(IncidentTeam::isRuledOutFor($incident, $this->commerce->id))->toBeFalse();
})->with([
    'the severity rises' => [fn () => $this->preNotice->update(['severity' => Severity::Critical])],
    'the incident is exploited' => [fn () => $this->preNotice->update(['is_exploited' => true, 'exploited_basis' => ['method' => 'keyword', 'excerpt' => 'It is actively exploited.', 'content_hash' => 'x']])],
    'the vendor confirms the branch' => [fn () => $this->preNotice->update(['affected_branches' => ['8.3']])],
    'a service on a branch the vendor did not rule out' => [fn () => assessedRuntime($this->commerce, 'shop-web', '8.4.26')],
    'the service moves to another branch' => [fn () => $this->runtime->update(['raw_version' => '8.4.26', 'version' => '8.4.26', 'branch' => '8.4'])],
]);

it('ends an assessment made while the branch was ruled out once the vendor stops ruling it out', function () {
    $this->preNotice->update(['unaffected_branches' => ['8.3']]);
    $incident = toldIncident($this->preNotice);
    assessIncident($incident, $this->commerce, IncidentImpact::No);

    $this->preNotice->update(['unaffected_branches' => []]);

    expect(holdsFor($incident, $this->commerce))->toBeFalse();
});

it('ends an assessment once a pre-notice it covered is split off, and the new incident starts unassessed', function () {
    $second = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP security advisory', 'severity' => Severity::High, 'aliases' => ['CVE-2026-1001'], 'published_at' => '2026-10-20 20:40:00']);
    $incident = toldIncident($this->preNotice);
    expect($second->refresh()->incident_id)->toBe($incident->id);
    assessIncident($incident, $this->commerce, IncidentImpact::No);

    $split = app(SplitNotice::class)->handle($second, User::factory()->admin()->create());

    expect(holdsFor($incident, $this->commerce))->toBeFalse()
        ->and(IncidentTeam::where('incident_id', $split->id)->where('team_id', $this->commerce->id)->value('impact'))->toBeNull();
});

it('ends the surviving incident\'s assessment when another issue is merged in, even at the same severity and branches', function (Severity $severity) {
    $incident = toldIncident($this->preNotice);
    assessIncident($incident, $this->commerce, IncidentImpact::No);
    $other = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Another PHP issue', 'severity' => $severity, 'aliases' => ['CVE-2026-2002'], 'published_at' => '2026-10-20 20:50:00']);
    app(AssignNoticeToIncident::class)->assignUnassigned();
    $otherIncident = $other->refresh()->incident;
    assessIncident($otherIncident, $this->commerce, IncidentImpact::Yes);

    app(MergeIncidents::class)->handle($otherIncident, $incident, User::factory()->admin()->create());

    expect(holdsFor($incident, $this->commerce))->toBeFalse()
        ->and(IncidentTeam::isRuledOutFor($incident, $this->commerce->id))->toBeFalse();
})->with([
    'same severity' => [Severity::High],
    'more severe' => [Severity::Critical],
]);

it('keeps an assessment when a pre-notice joins the incident on a shared CVE id, and ends it for one without a shared id', function (array $aliases, bool $holds) {
    $incident = toldIncident($this->preNotice);
    assessIncident($incident, $this->commerce, IncidentImpact::No);
    $joining = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'PHP security advisory', 'severity' => Severity::High, 'aliases' => $aliases, 'published_at' => '2026-10-20 20:50:00']);
    app(AssignNoticeToIncident::class)->assignUnassigned();

    if ($joining->refresh()->incident_id !== $incident->id) {
        app(MergeIncidents::class)->handle($joining->incident, $incident, User::factory()->admin()->create());
    }

    expect($joining->refresh()->incident_id)->toBe($incident->id)
        ->and(holdsFor($incident, $this->commerce))->toBe($holds);
})->with([
    'shared CVE id' => [['CVE-2026-1001', 'CVE-2026-9999'], true],
    'no shared id, merged by an admin' => [['CVE-2026-9999'], false],
    'no id at all, merged by an admin' => [[], false],
]);

it('leaves a team that only assessed the merged incident unassessed', function () {
    $incident = toldIncident($this->preNotice);
    $other = Advisory::factory()->preNotice(Technology::Php)->create(['title' => 'Another PHP issue', 'severity' => Severity::High, 'aliases' => ['CVE-2026-2002'], 'published_at' => '2026-10-20 20:50:00']);
    app(AssignNoticeToIncident::class)->assignUnassigned();
    $otherIncident = $other->refresh()->incident;
    assessIncident($otherIncident, $this->commerce, IncidentImpact::No);

    app(MergeIncidents::class)->handle($otherIncident, $incident, User::factory()->admin()->create());

    expect(IncidentTeam::where('incident_id', $incident->id)->where('team_id', $this->commerce->id)->value('impact'))->toBeNull();
});

it('resets a stale assessment once in the alert run, and still replies about the change', function () {
    $incident = toldIncident($this->preNotice);
    $row = assessIncident($incident, $this->commerce, IncidentImpact::No);

    $this->travel(1)->hour();
    $this->preNotice->update(['severity' => Severity::Critical]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Mức độ tăng từ High lên Critical.')
        ->and($row->refresh())
        ->impact->toBeNull()
        ->acknowledged_at->toBeNull()
        ->acknowledged_exposure->toBeNull()
        ->assessment_revision->toBe(2)
        ->and(IncidentTeamEvent::where('type', IncidentTeamEventType::ImpactReset)->sole()->data)->toMatchArray(['impact' => 'no']);

    $this->travel(1)->hour();
    $this->preNotice->update(['affected_branches' => ['8.3']]);
    app(SendPreNoticeAlerts::class)->handle();

    expect(IncidentTeamEvent::where('type', IncidentTeamEventType::ImpactReset)->count())->toBe(1);
});

/**
 * An open "thiếu bản vá" finding of the runtime asking for `$latest`, first seen now.
 */
function unpatchedRuntimeFinding(Runtime $runtime, string $latest): Finding
{
    return Finding::factory()->for($runtime->deployment)->create([
        'rule' => FindingRule::RuntimeOutdatedSecurity,
        'runtime_id' => $runtime->id,
        'severity' => Severity::High,
        'group_key' => "runtime_outdated_security:php:{$latest}",
        'context' => ['latest' => $latest],
        'first_seen_at' => now(),
        'sla_due_at' => now()->addDays(14),
    ]);
}

it('sends no fix alone to a team an admin said is not affected, and leaves its findings to alert on their own', function () {
    $incident = toldIncident($this->preNotice);
    assessIncident($incident, $this->commerce, IncidentImpact::No);

    $this->travel(1)->hour();
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    $finding = unpatchedRuntimeFinding($this->runtime, '8.3.36');

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(0)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(1)
        ->and(IncidentTeamPatch::count())->toBe(0)
        ->and($finding->refresh())->severity->toBe(Severity::High)->notified_severity->toBeNull()
        ->and($finding->context)->not->toHaveKey('covered_by_patch_id')
        ->and(holdsFor($incident, $this->commerce))->toBeTrue()
        ->and(app(SendFindingAlerts::class)->handle())->toBe(1)
        ->and(chatMessagesTo('COMMERCE'))->toHaveCount(2);
});

it('sends the fix to a team an admin said is affected or not sure about, and the card covers its findings', function (IncidentImpact $impact) {
    $incident = toldIncident($this->preNotice);
    assessIncident($incident, $this->commerce, $impact);

    $this->travel(1)->hour();
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    $finding = unpatchedRuntimeFinding($this->runtime, '8.3.36');

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatMessagesTo('COMMERCE')->last()['text'])->toBe('[High] Đã có bản vá, nâng lên 8.3.36')
        ->and($finding->refresh()->context['covered_by_patch_id'])->toBe(IncidentTeamPatch::sole()->id)
        ->and(holdsFor($incident, $this->commerce))->toBeTrue();
})->with([IncidentImpact::Yes, IncidentImpact::Unknown]);

it('sends the fix again once a "Không" stops holding', function () {
    $incident = toldIncident($this->preNotice);
    assessIncident($incident, $this->commerce, IncidentImpact::No);
    $this->travel(1)->hour();
    $this->preNotice->update(['patch_versions' => ['8.3.36']]);
    RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);
    app(SendPreNoticeAlerts::class)->handle();

    $this->travel(1)->hour();
    $this->preNotice->update(['affected_branches' => ['8.3']]);

    expect(app(SendPreNoticeAlerts::class)->handle())->toBe(1)
        ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Hãng đã xác nhận bản vá 8.3.36.', 'Hãng xác nhận thêm nhánh bị ảnh hưởng: 8.3.')
        ->and(IncidentTeamPatch::sole()->version)->toBe('8.3.36');
});

describe('the daily digest', function () {
    beforeEach(function () {
        $this->incident = toldIncident($this->preNotice);
        $this->travelTo('2026-10-22 01:00:00');
    });

    it('reminds every day of an incident told at an immediate severity until an admin marks it as read', function () {
        expect(app(SendDailyDigest::class)->handle())->toBe(1)
            ->and(chatJson(chatMessagesTo('COMMERCE')->last()))->toContain('Sự kiện chờ admin đánh giá ảnh hưởng', '[High] Upcoming Critical PHP Security Release', 'Đánh giá ảnh hưởng', "/admin/incidents?team={$this->commerce->id}&attention=1")
            ->not->toContain('Đang chờ bản vá');

        $this->travelTo('2026-10-23 01:00:00');

        expect(app(SendDailyDigest::class)->handle())->toBe(1);

        assessIncident($this->incident, $this->commerce, IncidentImpact::Unknown);
        $this->travelTo('2026-10-24 01:00:00');

        expect(app(SendDailyDigest::class)->handle())->toBe(0)
            ->and(app(SendDailyDigest::class)->digestFor($this->commerce))
            ->incident_reminders->toBeEmpty()
            ->awaiting_patch->toHaveCount(1);
    });

    it('takes an incident marked "Không" off the team\'s awaiting list, until the assessment stops holding', function () {
        assessIncident($this->incident, $this->commerce, IncidentImpact::No);

        expect(app(SendDailyDigest::class)->digestFor($this->commerce))->incident_reminders->toBeEmpty()->awaiting_patch->toBeEmpty()
            ->and(app(SendDailyDigest::class)->handle())->toBe(0);

        $this->preNotice->update(['severity' => Severity::Critical]);

        expect(app(SendDailyDigest::class)->digestFor($this->commerce))->incident_reminders->toHaveCount(1)
            ->and(app(SendDailyDigest::class)->handle())->toBe(1);
    });

    it('reminds of an incident told at the immediate severities or of unknown severity only', function (Severity $told, bool $isReminded) {
        IncidentTeamNotice::query()->update(['severity' => $told]);

        expect(app(SendDailyDigest::class)->digestFor($this->commerce)['incident_reminders'])->toHaveCount((int) $isReminded);
    })->with([
        'critical' => [Severity::Critical, true],
        'unknown' => [Severity::Unknown, true],
        'medium' => [Severity::Medium, false],
    ]);

    it('reminds of a service whose branch is not known, listed by its version', function () {
        $this->runtime->update(['branch' => null, 'version' => null, 'raw_version' => '8.3.35-1+deb']);

        expect(app(SendDailyDigest::class)->digestFor($this->commerce)['incident_reminders'])->toHaveCount(1)
            ->and(app(SendDailyDigest::class)->digestFor($this->commerce)['incident_reminders'][0]['branches'])->toBe(['8.3.35-1+deb']);

        assessIncident($this->incident, $this->commerce, IncidentImpact::Yes);

        expect(app(SendDailyDigest::class)->digestFor($this->commerce)['incident_reminders'])->toBeEmpty();
    });

    it('reads the branch of a service from its version as the alerts do, so a ruled-out one is not reminded of', function () {
        $this->runtime->update(['branch' => null, 'version' => '8.3.35']);
        $this->preNotice->update(['unaffected_branches' => ['8.3']]);

        expect(IncidentTeamExposure::for($this->incident->refresh(), $this->commerce)->services[0]['scope'])->toBe('not_affected')
            ->and(app(SendDailyDigest::class)->digestFor($this->commerce)['incident_reminders'])->toBeEmpty();
    });

    it('does not remind once the vendor confirmed the fix for every branch the team runs', function () {
        $this->preNotice->update(['patch_versions' => ['8.3.36']]);
        RuntimeRelease::factory()->security()->create(['branch' => '8.3', 'version' => '8.3.36']);

        expect(app(SendDailyDigest::class)->digestFor($this->commerce)['incident_reminders'])->toBeEmpty();
    });
});

it('keeps the full exposure of each assessment and reset in the history', function () {
    $incident = toldIncident($this->preNotice);
    $shown = IncidentTeamExposure::for($incident, $this->commerce)->toArray();
    $row = assessIncident($incident, $this->commerce, IncidentImpact::No, note: 'Chỉ chạy cron');

    $this->preNotice->update(['severity' => Severity::Critical]);
    [$locked] = IncidentTeam::lockFor($incident, $this->commerce);
    $locked->resetStaleAssessment($current = IncidentTeamExposure::for($incident->refresh(), $this->commerce));

    [$acknowledged, $reset] = $row->events()->orderBy('id')->get()->all();
    expect($acknowledged->data['exposure'])->toEqual($shown)
        ->and($reset->data)->toMatchArray(['impact' => 'no', 'note' => 'Chỉ chạy cron', 'acknowledged_exposure' => $shown, 'exposure' => $current->toArray()])
        ->and($shown['issue_ids'])->toBe(['CVE-2026-1001']);
});

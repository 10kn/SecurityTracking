<?php

use App\Enums\IncidentAlertReason;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\Incident;
use App\Models\IncidentSuggestion;
use App\Models\IncidentTeam;
use App\Models\IncidentTeamNotice;
use App\Models\Team;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->travelTo('2026-11-05 10:00:00');
    config(['psm.notices.collect_days' => 90, 'psm.notices.relevant_days' => 14]);
});

/**
 * An incident of the pre-notices published at the given times.
 *
 * @param  list<string|null>  $publishedAt
 */
function incidentOfNotices(array $publishedAt, Technology $technology = Technology::Php): Incident
{
    $incident = Incident::factory()->create(['technology' => $technology]);

    foreach ($publishedAt as $time) {
        Advisory::factory()->preNotice($technology)->create(['incident_id' => $incident->id, 'published_at' => $time]);
    }

    return $incident;
}

it('deletes an incident whose every pre-notice came out before the window, with its pre-notices and suggestions', function () {
    $old = incidentOfNotices(['2019-08-29 11:55:58', '2019-08-30 09:00:00']);
    $oldNode = incidentOfNotices(['2020-01-07 00:00:00'], Technology::Nodejs);
    $recent = incidentOfNotices(['2026-10-21 00:35:26']);
    $straddling = incidentOfNotices(['2019-08-29 11:55:58', '2026-10-21 00:35:26']);
    $undated = incidentOfNotices(['2019-08-29 11:55:58', null]);
    $orphan = Advisory::factory()->preNotice(Technology::Php)->create(['published_at' => '2018-01-01 00:00:00']);
    $suggestion = IncidentSuggestion::factory()->create(['advisory_id' => $recent->notices()->value('id'), 'incident_id' => $old->id]);

    artisan('psm:prune-notices --force')
        ->expectsOutputToContain('Đã xoá 2 sự kiện và 4 tin.')
        ->assertSuccessful();

    expect(Incident::pluck('id')->all())->toEqualCanonicalizing([$recent->id, $straddling->id, $undated->id])
        ->and(Advisory::whereIn('incident_id', [$old->id, $oldNode->id])->exists())->toBeFalse()
        ->and(Advisory::whereKey($orphan->id)->exists())->toBeFalse()
        ->and(Advisory::count())->toBe(5)
        ->and(IncidentSuggestion::whereKey($suggestion->id)->exists())->toBeFalse();
});

it('keeps an old incident a team was told about or assessed, or another incident was merged into', function () {
    $team = Team::factory()->create();
    $told = incidentOfNotices(['2019-08-29 11:55:58']);
    IncidentTeamNotice::create(['team_id' => $team->id, 'advisory_id' => $told->notices()->value('id'), 'severity' => Severity::High, 'reason' => IncidentAlertReason::New]);
    $assessed = incidentOfNotices(['2019-08-29 11:55:58']);
    IncidentTeam::create(['incident_id' => $assessed->id, 'team_id' => $team->id, 'thread_key' => 'psm-test', 'generation' => 1]);
    $mergedInto = incidentOfNotices(['2019-08-29 11:55:58']);
    Incident::factory()->create(['merged_into_id' => $mergedInto->id]);
    $news = incidentOfNotices(['2019-08-29 11:55:58']);
    $news->notices()->update(['news_notification_id' => ChatNotification::factory()->create()->id]);

    artisan('psm:prune-notices --force')->assertSuccessful();

    expect(Incident::whereIn('id', [$told->id, $assessed->id, $mergedInto->id, $news->id])->count())->toBe(4)
        ->and(Advisory::count())->toBe(4);
});

it('only counts when pretending, and deletes nothing unless the admin confirms', function () {
    incidentOfNotices(['2019-08-29 11:55:58']);

    artisan('psm:prune-notices --pretend')->assertSuccessful();
    artisan('psm:prune-notices')
        ->expectsConfirmation('Xoá vĩnh viễn các tin đăng trước 2026-08-07? Không khôi phục được.', 'no')
        ->assertSuccessful();

    expect(Incident::count())->toBe(1)->and(Advisory::count())->toBe(1);
});

it('takes the window from --days, never shorter than the alerts look back', function (string $days, int $left) {
    incidentOfNotices(['2026-10-01 00:00:00']);
    incidentOfNotices(['2026-10-30 00:00:00']);

    artisan("psm:prune-notices --force --days={$days}")->assertSuccessful();

    expect(Incident::count())->toBe($left);
})->with([
    '30 days' => ['30', 1],
    '1 day, raised to 14' => ['1', 1],
    '60 days' => ['60', 2],
]);

it('says so when nothing is old enough', function () {
    incidentOfNotices(['2026-10-21 00:35:26']);

    artisan('psm:prune-notices --force')
        ->expectsOutputToContain('Không có tin nào đăng trước 2026-08-07.')
        ->assertSuccessful();
});

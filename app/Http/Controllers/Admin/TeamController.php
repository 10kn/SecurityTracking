<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FindingState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TeamRequest;
use App\Http\Resources\Admin\ProjectResource;
use App\Http\Resources\Admin\TeamResource;
use App\Models\Team;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest($request, ['name', 'projects_count', 'open_findings_count'], 'name');

        $teams = $table->search(Team::query(), ['name', 'slug'])
            ->with('latestChatNotification')
            ->withCount(['projects', 'findings as open_findings_count' => fn (Builder $query) => $query->where('state', FindingState::Open)])
            ->orderBy($table->sort, $table->direction())
            ->orderBy('id')
            ->paginate($table->perPage)
            ->withQueryString();

        return Inertia::render('teams/index', [
            'teams' => TeamResource::collection($teams),
            'table' => $table->toArray(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('teams/create');
    }

    public function store(TeamRequest $request): RedirectResponse
    {
        $team = Team::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã tạo team :team.', ['team' => $team->name])]);

        return to_route('admin.teams.edit', $team);
    }

    public function edit(Team $team): Response
    {
        $team->load('latestChatNotification');

        $projects = $team->projects()->withCount('services')->orderBy('name')->get();

        return Inertia::render('teams/edit', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'googleChat' => [
                'masked_webhook_url' => $team->maskedGoogleChatWebhookUrl(),
                'status' => $team->googleChatStatus()->dot(),
                'detail' => $this->latestDeliveryDetail($team),
            ],
            'projects' => ProjectResource::collection($projects),
        ]);
    }

    public function update(TeamRequest $request, Team $team): RedirectResponse
    {
        $team->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã lưu team :team.', ['team' => $team->name])]);

        return to_route('admin.teams.edit', $team);
    }

    /**
     * Projects restrict deleting their team, so they move to another team first.
     */
    public function destroy(Team $team): RedirectResponse
    {
        if ($team->projects()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Team còn project. Chuyển project sang team khác trước.')]);

            return back();
        }

        $team->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã xóa team :team.', ['team' => $team->name])]);

        return to_route('admin.teams.index');
    }

    /**
     * E.g. "tin gần nhất 23/10/2026 08:00", or the error of the last failed delivery.
     */
    private function latestDeliveryDetail(Team $team): ?string
    {
        $latest = $team->latestChatNotification;

        if (blank($team->google_chat_webhook_url) || $latest === null) {
            return null;
        }

        return $latest->failed_at !== null
            ? __('lỗi lúc :time: :error', ['time' => $latest->failed_at->translatedFormat(__('d/m/Y H:i')), 'error' => $latest->last_error])
            : __('tin gần nhất :time', ['time' => $latest->sent_at?->translatedFormat(__('d/m/Y H:i'))]);
    }
}

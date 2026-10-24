<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\Technology;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records for the ⌘K palette: a few projects, services, teams and technologies whose name or slug contains the text.
 */
class SearchController extends Controller
{
    private const int LIMIT_PER_TYPE = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $search = mb_substr(trim($request->string('query')->toString()), 0, 100);

        if ($search === '') {
            return response()->json(['results' => []]);
        }

        $pattern = '%'.addcslashes($search, '\\%_').'%';

        $results = [
            ...$this->matching(Project::query(), $pattern)->with('team')->orderBy('name')->limit(self::LIMIT_PER_TYPE)->get()
                ->map(fn (Project $project): array => [
                    'label' => $project->name,
                    'hint' => __('Project · :team', ['team' => $project->team->name]),
                    'url' => route('admin.projects.show', $project, false),
                ]),
            ...$this->matching(Service::query(), $pattern)->with('project')->orderBy('name')->limit(self::LIMIT_PER_TYPE)->get()
                ->map(fn (Service $service): array => [
                    'label' => $service->name,
                    'hint' => __('Service · :project', ['project' => $service->project->name]),
                    'url' => route('admin.services.edit', $service, false),
                ]),
            ...$this->matching(Team::query(), $pattern)->orderBy('name')->limit(self::LIMIT_PER_TYPE)->get()
                ->map(fn (Team $team): array => [
                    'label' => $team->name,
                    'hint' => __('Team'),
                    'url' => route('admin.teams.edit', $team, false),
                ]),
            ...$this->matching(Technology::query(), $pattern)->orderBy('name')->limit(self::LIMIT_PER_TYPE)->get()
                ->map(fn (Technology $technology): array => [
                    'label' => $technology->name,
                    'hint' => __('Công nghệ'),
                    'url' => route('admin.technologies.edit', $technology, false),
                ]),
        ];

        return response()->json(['results' => $results]);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function matching(Builder $query, string $pattern): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereLike('name', $pattern)->orWhereLike('slug', $pattern));
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CatalogStatus;
use App\Enums\Technology;
use App\Http\Controllers\Controller;
use App\Models\Runtime;
use App\Models\RuntimeBranch;
use App\Models\RuntimeRelease;
use App\Models\SourceHeartbeat;
use App\Support\TableQuery;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The supported technologies are defined in code (App\Enums\Technology). Admins only read their version catalogs and
 * sync them on demand.
 */
class TechnologyController extends Controller
{
    public function index(): Response
    {
        $branchCounts = RuntimeBranch::toBase()->selectRaw('technology, count(*) as aggregate')->groupBy('technology')->pluck('aggregate', 'technology');
        $runtimeCounts = Runtime::toBase()->selectRaw('technology, count(*) as aggregate')->groupBy('technology')->pluck('aggregate', 'technology');
        $heartbeats = SourceHeartbeat::whereIn('source', array_map(fn (Technology $technology): string => $technology->catalogSourceKey(), Technology::cases()))->get()->keyBy('source');

        return Inertia::render('technologies/index', [
            'technologies' => collect(Technology::cases())
                ->sortBy(fn (Technology $technology): string => $technology->getLabel())
                ->map(fn (Technology $technology): array => [
                    ...$this->summary($technology, $heartbeats->get($technology->catalogSourceKey())),
                    'branches_count' => (int) ($branchCounts[$technology->value] ?? 0),
                    'runtimes_count' => (int) ($runtimeCounts[$technology->value] ?? 0),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * The branches with their support dates, and every known release, newest first, filtered by branch or version.
     */
    public function show(Request $request, Technology $technology): Response
    {
        $table = TableQuery::fromRequest($request, ['version'], '-version');
        $branchFilter = $request->string('branch')->toString() ?: null;

        $branches = RuntimeBranch::where('technology', $technology)
            ->get()
            ->sort(fn (RuntimeBranch $a, RuntimeBranch $b): int => version_compare($b->branch, $a->branch))
            ->values();

        $releases = RuntimeRelease::where('technology', $technology)
            ->when($branchFilter !== null, fn ($query) => $query->where('branch', $branchFilter))
            ->when($table->search !== '', fn ($query) => $query->whereLike('version', '%'.addcslashes($table->search, '\\%_').'%'))
            ->get()
            ->sort(fn (RuntimeRelease $a, RuntimeRelease $b): int => version_compare($b->version, $a->version))
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $releasePage = new LengthAwarePaginator(
            $releases->forPage($page, $table->perPage)->map(fn (RuntimeRelease $release): array => [
                'version' => $release->version,
                'branch' => $release->branch,
                'released_at' => $this->date($release->released_at),
                'is_security_release' => $release->is_security_release,
            ])->values(),
            $releases->count(),
            $table->perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('technologies/show', [
            'technology' => [
                ...$this->summary($technology, $technology->catalogHeartbeat()),
                'endoflife_url' => $technology->endoflifeUrl(),
                'release_source_url' => $technology->releaseSourceUrl(),
                'runtimes_count' => Runtime::where('technology', $technology)->count(),
            ],
            'branches' => $branches->map(fn (RuntimeBranch $branch): array => [
                'branch' => $branch->branch,
                'latest_version' => $branch->latest_version,
                'released_at' => $this->date($branch->released_at),
                'active_support_until' => $this->date($branch->active_support_until),
                'security_support_until' => $this->date($branch->security_support_until),
                'support' => $branch->support()->dot(),
            ])->all(),
            'releases' => JsonResource::collection($releasePage),
            'table' => [...$table->toArray(), 'branch' => $branchFilter],
        ]);
    }

    /**
     * @return array{value: string, name: string, release_source: string, catalog: array{label: string, tone: string}, last_synced: string|null, last_error: string|null}
     */
    private function summary(Technology $technology, ?SourceHeartbeat $heartbeat): array
    {
        return [
            'value' => $technology->value,
            'name' => $technology->getLabel(),
            'release_source' => $technology->releaseSourceLabel(),
            'catalog' => CatalogStatus::fromHeartbeat($heartbeat)->dot(),
            'last_synced' => $heartbeat?->last_success_at === null
                ? null
                : __('lần cuối :time', ['time' => $heartbeat->last_success_at->diffForHumans()]),
            'last_error' => $heartbeat?->last_error,
        ];
    }

    private function date(?CarbonInterface $date): ?string
    {
        return $date?->translatedFormat(__('d/m/Y'));
    }
}

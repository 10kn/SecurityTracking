<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReleaseSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TechnologyRequest;
use App\Http\Resources\Admin\TechnologyResource;
use App\Jobs\SyncTechnologyCatalogJob;
use App\Models\Technology;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TechnologyController extends Controller
{
    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest($request, ['name', 'branches_count', 'runtimes_count'], 'name');

        $technologies = $table->search(Technology::query(), ['name', 'slug', 'endoflife_product'])
            ->withCount(['branches', 'runtimes'])
            ->orderBy($table->sort, $table->direction())
            ->orderBy('id')
            ->paginate($table->perPage)
            ->withQueryString();

        return Inertia::render('technologies/index', [
            'technologies' => TechnologyResource::collection($technologies),
            'table' => $table->toArray(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('technologies/create', [
            'releaseSources' => $this->releaseSourceOptions(),
        ]);
    }

    /**
     * The first sync runs right away so the version list is ready when the technology is picked on a service.
     */
    public function store(TechnologyRequest $request): RedirectResponse
    {
        $technology = Technology::create($request->validated());

        SyncTechnologyCatalogJob::dispatch($technology);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã thêm :technology và đưa vào hàng đợi đồng bộ.', ['technology' => $technology->name])]);

        return to_route('admin.technologies.index');
    }

    public function edit(Technology $technology): Response
    {
        return Inertia::render('technologies/edit', [
            'technology' => [
                ...$technology->only(['id', 'name', 'slug', 'endoflife_product']),
                'release_source' => $technology->release_source->value,
                'is_used' => $technology->runtimes()->exists(),
            ],
            'releaseSources' => $this->releaseSourceOptions(),
        ]);
    }

    /**
     * A different product or release source means a different catalog, so sync it now.
     */
    public function update(TechnologyRequest $request, Technology $technology): RedirectResponse
    {
        $technology->update($request->validated());

        if ($technology->wasChanged(['endoflife_product', 'release_source'])) {
            SyncTechnologyCatalogJob::dispatch($technology);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã lưu :technology.', ['technology' => $technology->name])]);

        return to_route('admin.technologies.edit', $technology);
    }

    /**
     * Runtimes restrict deleting their technology.
     */
    public function destroy(Technology $technology): RedirectResponse
    {
        if ($technology->runtimes()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Còn service đang dùng công nghệ này.')]);

            return back();
        }

        $technology->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã xóa :technology.', ['technology' => $technology->name])]);

        return to_route('admin.technologies.index');
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    private function releaseSourceOptions(): array
    {
        return array_map(fn (ReleaseSource $source): array => [
            'value' => $source->value,
            'label' => $source->getLabel(),
            'description' => $source->getDescription(),
        ], ReleaseSource::cases());
    }
}

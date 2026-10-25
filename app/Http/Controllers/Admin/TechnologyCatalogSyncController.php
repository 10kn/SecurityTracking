<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Technology;
use App\Http\Controllers\Controller;
use App\Jobs\SyncTechnologyCatalogJob;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TechnologyCatalogSyncController extends Controller
{
    public function store(Technology $technology): RedirectResponse
    {
        SyncTechnologyCatalogJob::dispatch($technology);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã đưa :technology vào hàng đợi đồng bộ.', ['technology' => $technology->getLabel()])]);

        return back();
    }
}

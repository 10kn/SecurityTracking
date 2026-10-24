<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncTechnologyCatalogJob;
use App\Models\Technology;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TechnologyCatalogSyncController extends Controller
{
    public function store(Technology $technology): RedirectResponse
    {
        SyncTechnologyCatalogJob::dispatch($technology);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã đưa :technology vào hàng đợi đồng bộ.', ['technology' => $technology->name])]);

        return back();
    }
}

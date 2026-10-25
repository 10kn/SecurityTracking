<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncServiceLockfilesJob;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ServiceLockfileSyncController extends Controller
{
    public function store(Service $service): RedirectResponse
    {
        abort_unless($service->syncsLockfiles(), 404);

        SyncServiceLockfilesJob::dispatch($service);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã đưa :service vào hàng đợi lấy lockfile.', ['service' => $service->name])]);

        return back();
    }
}

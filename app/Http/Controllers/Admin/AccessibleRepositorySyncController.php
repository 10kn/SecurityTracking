<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SyncAccessibleRepositories;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

class AccessibleRepositorySyncController extends Controller
{
    /**
     * Runs while the admin waits, from the refresh button next to the repository list of the service form.
     */
    public function store(SyncAccessibleRepositories $syncAccessibleRepositories): RedirectResponse
    {
        try {
            $count = $syncAccessibleRepositories->handle();
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('Không lấy được danh sách repository từ GitHub'), 'description' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã cập nhật danh sách: :count repository.', ['count' => $count])]);

        return back();
    }
}

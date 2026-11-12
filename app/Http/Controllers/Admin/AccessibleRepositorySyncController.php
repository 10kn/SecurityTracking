<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SyncAccessibleRepositories;
use App\Http\Controllers\Controller;
use App\Models\RepositoryConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

class AccessibleRepositorySyncController extends Controller
{
    /**
     * Runs while the admin waits: from the refresh button next to the repository list of the service form, which
     * refreshes every source, or from a connection's page with `repository_connection_id`, which refreshes that one.
     */
    public function store(Request $request, SyncAccessibleRepositories $syncAccessibleRepositories): RedirectResponse
    {
        $validated = $request->validate(['repository_connection_id' => ['nullable', 'integer']]);
        $connection = isset($validated['repository_connection_id']) ? RepositoryConnection::findOrFail($validated['repository_connection_id']) : null;

        try {
            ['count' => $count, 'problems' => $problems] = $connection === null
                ? $syncAccessibleRepositories->handleAll()
                : ['count' => $syncAccessibleRepositories->handle($connection), 'problems' => []];
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('Không lấy được danh sách repository từ GitHub'), 'description' => $exception->getMessage()]);

            return back();
        }

        if ($problems !== []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Không lấy được danh sách repository từ GitHub'), 'description' => implode("\n", $problems)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã cập nhật danh sách: :count repository.', ['count' => $count])]);

        return back();
    }
}

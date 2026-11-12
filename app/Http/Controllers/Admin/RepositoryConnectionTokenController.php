<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveRepositoryConnection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RepositoryConnectionTokenRequest;
use App\Models\RepositoryConnection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class RepositoryConnectionTokenController extends Controller
{
    /**
     * Replaces the token once GitHub accepts the new one; the old one stays until then.
     */
    public function update(RepositoryConnectionTokenRequest $request, RepositoryConnection $repositoryConnection, SaveRepositoryConnection $saveRepositoryConnection): RedirectResponse
    {
        ['repositories_count' => $count, 'problem' => $problem] = $saveRepositoryConnection->handle($repositoryConnection, $request->validated());

        Inertia::flash('toast', $problem === null
            ? ['type' => 'success', 'message' => __('Đã thay token: account :account đọc được :count repository.', ['account' => $repositoryConnection->account_login, 'count' => $count])]
            : ['type' => 'error', 'message' => __('Đã thay token nhưng chưa lấy được danh sách repository.'), 'description' => $problem]);

        return to_route('admin.repository-connections.edit', $repositoryConnection);
    }
}

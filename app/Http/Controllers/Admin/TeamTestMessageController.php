<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SendTestChatMessage;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TeamTestMessageController extends Controller
{
    public function store(Team $team, SendTestChatMessage $sendTestChatMessage): RedirectResponse
    {
        $error = $sendTestChatMessage->handle($team);

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => __('Gửi thử thành công.')]
            : ['type' => 'error', 'message' => __('Gửi thử thất bại'), 'description' => $error]);

        return to_route('admin.teams.edit', $team);
    }
}

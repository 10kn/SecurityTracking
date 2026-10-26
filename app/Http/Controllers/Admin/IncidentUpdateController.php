<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SendIncidentUpdate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendIncidentUpdateRequest;
use App\Models\Incident;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use InvalidArgumentException;

class IncidentUpdateController extends Controller
{
    /**
     * Sends the admin's urgent update to the teams confirmed in the form (see SendIncidentUpdate). The form keeps its
     * request id until the server confirms it with `incidentUpdate` (queued, or already sent under that id), so a
     * retry or a double click sends nothing twice while the next update is a new request. It never marks the incident
     * as read for a team.
     */
    public function store(SendIncidentUpdateRequest $request, Incident $incident, SendIncidentUpdate $sendIncidentUpdate): RedirectResponse
    {
        try {
            ['queued' => $queued] = $sendIncidentUpdate->handle($incident, $request->validated('message'), $request->teamIds(), $request->user(), $request->validated('request_id'));
        } catch (InvalidArgumentException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Không gửi được: sự kiện đã được gộp hoặc không còn tin nào.')]);

            return redirect()->route('admin.incidents.show', $incident);
        }

        Inertia::flash('incidentUpdate', ['request_id' => $request->validated('request_id'), 'status' => $queued === 0 ? 'duplicate' : 'queued']);
        Inertia::flash('toast', $queued === 0
            ? ['type' => 'success', 'message' => __('Cập nhật này đã được gửi trước đó, không gửi lại.')]
            : ['type' => 'success', 'message' => __('Đã gửi cập nhật tới :count team.', ['count' => $queued])]);

        return redirect()->route('admin.incidents.show', $incident);
    }
}

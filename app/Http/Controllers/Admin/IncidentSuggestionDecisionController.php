<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AcceptIncidentSuggestion;
use App\Enums\IncidentSuggestionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DecideIncidentSuggestionRequest;
use App\Models\IncidentSuggestion;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use InvalidArgumentException;

class IncidentSuggestionDecisionController extends Controller
{
    /**
     * Accepting moves only the suggested pre-notice into the suggested incident (see AcceptIncidentSuggestion) and
     * opens that incident; rejecting closes only this suggestion. A suggestion decided meanwhile, or whose pre-notice
     * or incidents changed, is left as it is.
     */
    public function store(DecideIncidentSuggestionRequest $request, IncidentSuggestion $suggestion, AcceptIncidentSuggestion $acceptIncidentSuggestion): RedirectResponse
    {
        if (! $request->isAccepted()) {
            $isRejected = IncidentSuggestion::whereKey($suggestion->id)
                ->where('status', IncidentSuggestionStatus::Pending)
                ->update(['status' => IncidentSuggestionStatus::Rejected, 'decided_by' => $request->user()->id, 'decided_at' => now()]) === 1;

            Inertia::flash('toast', $isRejected
                ? ['type' => 'success', 'message' => __('Đã bỏ qua gợi ý.')]
                : ['type' => 'error', 'message' => __('Gợi ý đã được xử lý từ trước.')]);

            return redirect()->back();
        }

        try {
            $incident = $acceptIncidentSuggestion->handle($suggestion, (int) $request->validated('incident_id'), (int) $request->validated('target_id'), $request->user());
        } catch (InvalidArgumentException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Gợi ý, tin hoặc sự kiện đã thay đổi từ lúc mở trang nên chưa gộp. Xem lại rồi thử lại.')]);

            return redirect()->back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã chuyển tin vào sự kiện này.')]);

        return redirect()->route('admin.incidents.show', $incident);
    }
}

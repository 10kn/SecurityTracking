<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AcknowledgeIncidentTeams;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AcknowledgeIncidentTeamsRequest;
use App\Models\Incident;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class IncidentAcknowledgementController extends Controller
{
    /**
     * Marks the incident as read for the picked teams, with their impact. Teams whose exposure changed since the page
     * was loaded, or that another admin assessed meanwhile, are left as they are and counted in the toast.
     */
    public function store(AcknowledgeIncidentTeamsRequest $request, Incident $incident, AcknowledgeIncidentTeams $acknowledgeIncidentTeams): RedirectResponse
    {
        ['marked' => $marked, 'changed' => $changed, 'conflicts' => $conflicts, 'skipped' => $skipped] = $acknowledgeIncidentTeams->handle($request->user(), $incident, $request->assessments());

        $description = collect([
            $changed === 0 ? null : __(':count team đã thay đổi từ lúc mở trang (tin mới, phạm vi, mức độ hoặc bản vá) nên chưa được lưu. Xem lại rồi đánh dấu.', ['count' => $changed]),
            $conflicts === 0 ? null : __(':count team vừa được admin khác đánh giá nên chưa được lưu. Xem đánh giá mới rồi sửa nếu cần.', ['count' => $conflicts]),
            $skipped === 0 ? null : __(':count team chưa được báo về sự kiện hoặc không còn chạy công nghệ này nên không đánh giá được.', ['count' => $skipped]),
        ])->filter()->join(' ');

        Inertia::flash('toast', array_filter([
            'type' => $marked === 0 ? 'error' : 'success',
            'message' => __('Đã đánh dấu đã đọc cho :count team. Hệ thống ngừng nhắc hằng ngày.', ['count' => $marked]),
            'description' => $description === '' ? null : $description,
        ]));

        return redirect()->route('admin.incidents.show', $incident);
    }
}

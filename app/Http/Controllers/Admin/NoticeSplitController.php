<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SplitNotice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SplitNoticeRequest;
use App\Models\Advisory;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use InvalidArgumentException;

class NoticeSplitController extends Controller
{
    /**
     * Takes the pre-notice out of the incident the admin saw it in into a new one (see SplitNotice) and opens it.
     */
    public function store(SplitNoticeRequest $request, Advisory $notice, SplitNotice $splitNotice): RedirectResponse
    {
        $expectedIncidentId = $request->integer('incident_id');

        try {
            $incident = $splitNotice->handle($notice, $request->user(), $expectedIncidentId);
        } catch (InvalidArgumentException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Chỉ tách được tin đang chung sự kiện với tin khác. Tin đã thay đổi từ lúc mở trang.')]);

            return redirect()->route('admin.incidents.show', $expectedIncidentId);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã tách tin thành sự kiện mới. Sự kiện mới chưa được đánh giá cho team nào.')]);

        return redirect()->route('admin.incidents.show', $incident);
    }
}

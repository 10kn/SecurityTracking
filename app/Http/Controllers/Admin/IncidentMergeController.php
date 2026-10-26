<?php

namespace App\Http\Controllers\Admin;

use App\Actions\MergeIncidents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MergeIncidentRequest;
use App\Models\Incident;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use InvalidArgumentException;

class IncidentMergeController extends Controller
{
    /**
     * Merges the incident into another current one of the same technology (see MergeIncidents) and opens that one.
     */
    public function store(MergeIncidentRequest $request, Incident $incident, MergeIncidents $mergeIncidents): RedirectResponse
    {
        try {
            $into = $mergeIncidents->handle($incident, Incident::findOrFail($request->integer('into_id')), $request->user());
        } catch (InvalidArgumentException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Chỉ gộp được hai sự kiện hiện hành cùng công nghệ. Một trong hai đã thay đổi từ lúc mở trang.')]);

            return redirect()->route('admin.incidents.show', $incident);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã gộp sự kiện #:from vào sự kiện này.', ['from' => $incident->id])]);

        return redirect()->route('admin.incidents.show', $into);
    }
}

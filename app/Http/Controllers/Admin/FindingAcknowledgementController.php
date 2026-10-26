<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AcknowledgeFindings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AcknowledgeFindingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;

class FindingAcknowledgementController extends Controller
{
    /**
     * Back to the list on its first page: the findings just marked may have been the only ones of the page.
     */
    public function store(AcknowledgeFindingsRequest $request, AcknowledgeFindings $acknowledgeFindings): RedirectResponse
    {
        ['marked' => $marked, 'changed' => $changed] = $acknowledgeFindings->handle($request->user(), $request->findingVersions());

        $description = $changed === 0 ? null : __(':count finding đã thay đổi từ lúc mở trang (mức tăng hoặc mở lại) nên chưa được đánh dấu. Xem lại rồi đánh dấu.', ['count' => $changed]);

        Inertia::flash('toast', array_filter([
            'type' => $marked === 0 && $changed > 0 ? 'error' : 'success',
            'message' => __('Đã đánh dấu :count finding là đã đọc. Hệ thống ngừng nhắc hằng ngày.', ['count' => $marked]),
            'description' => $description,
        ]));

        return redirect()->to($this->withoutPage(url()->previous(route('admin.findings.index'))));
    }

    private function withoutPage(string $url): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $query = Arr::except($query, 'page');

        return strtok($url, '?').($query === [] ? '' : '?'.http_build_query($query));
    }
}

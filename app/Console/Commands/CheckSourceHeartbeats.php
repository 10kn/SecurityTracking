<?php

namespace App\Console\Commands;

use App\Actions\SendAdminAlert;
use App\Enums\AdvisoryKind;
use App\Jobs\SendChatNotification;
use App\Models\Advisory;
use App\Models\ChatNotification;
use App\Models\SourceHeartbeat;
use App\Services\Notices\NoticeClassifier;
use App\Support\LocalTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

#[Signature('psm:check-sources')]
#[Description('Alert the admin space about every data source that has not synced successfully within its threshold, messages stuck in the queue and news the classifier cannot read')]
class CheckSourceHeartbeats extends Command
{
    /**
     * Handover 5.1: staying silent because of an error is the worst failure. Each outage alerts once: the dedupe key
     * holds the last success, so the next outage of the same source alerts again.
     */
    public function handle(SendAdminAlert $sendAdminAlert, NoticeClassifier $classifier): int
    {
        $stale = SourceHeartbeat::orderBy('source')->get()->filter(fn (SourceHeartbeat $heartbeat): bool => $heartbeat->isStale());

        foreach ($stale as $heartbeat) {
            $sendAdminAlert->handle(
                "source-stale:{$heartbeat->source}:".($heartbeat->last_success_at?->getTimestamp() ?? 'never'),
                __('Nguồn :source lỗi quá ngưỡng', ['source' => $heartbeat->name]),
                [
                    __('Thành công gần nhất: :time', ['time' => LocalTime::format($heartbeat->last_success_at) ?? __('chưa có')]),
                    __('Ngưỡng: :minutes phút', ['minutes' => $heartbeat->threshold_minutes]),
                    __('Lỗi gần nhất: :error', ['error' => $heartbeat->last_error ?? '—']),
                    __('Runtime và package phụ thuộc nguồn này được coi là Thiếu dữ liệu cho tới khi nguồn hoạt động lại.'),
                ],
            );
        }

        $this->components->info($stale->count().' stale sources.');

        $this->checkStuckMessages($sendAdminAlert);

        if ($classifier->isEnabled()) {
            $this->checkUnclassifiedNotices($sendAdminAlert);
        }

        return self::SUCCESS;
    }

    /**
     * Messages still waiting after `psm.alerts.stuck_after_minutes` mean the queue worker is down, so this alert does
     * not use the queue. It is sent once per stuck message that is the oldest one waiting, and tried again on every
     * check until it is delivered, even once the queue works again, also after it ran out of tries. These alerts are
     * not queued, so they never count as stuck themselves.
     */
    private function checkStuckMessages(SendAdminAlert $sendAdminAlert): void
    {
        ChatNotification::whereLike('dedupe_key', 'admin:queue-stuck:%')
            ->whereNull('sent_at')
            ->each(function (ChatNotification $notification): void {
                if ($notification->failed_at !== null) {
                    $notification->update(['failed_at' => null, 'attempts' => 0]);
                }

                Bus::dispatchNow(new SendChatNotification($notification));
            });

        $stuck = ChatNotification::whereNull('sent_at')
            ->whereNull('failed_at')
            ->whereNotLike('dedupe_key', 'admin:queue-stuck:%')
            ->where('created_at', '<', now()->subMinutes(config('psm.alerts.stuck_after_minutes')))
            ->orderBy('id');

        $oldest = $stuck->first();

        if ($oldest === null) {
            return;
        }

        $sendAdminAlert->handle(
            "queue-stuck:{$oldest->id}",
            __('Tin Google Chat bị kẹt trong hàng đợi'),
            [
                __(':count tin chưa gửi được sau :minutes phút.', ['count' => $stuck->count(), 'minutes' => config('psm.alerts.stuck_after_minutes')]),
                __('Tin cũ nhất tạo lúc :time.', ['time' => LocalTime::format($oldest->created_at)]),
                __('Kiểm tra queue worker (php artisan queue:work). Tin sẽ được gửi khi worker chạy lại.'),
            ],
            sendNow: true,
        );

        $this->components->warn(__('Tin Google Chat bị kẹt trong hàng đợi'));
    }

    /**
     * The classifier has failed on the current text of a pre-notice for `psm.notices.classifier.alert_after_minutes`,
     * whether the entry is new or was edited: it was sent with its keyword and CVSS severity only. One alert per
     * pre-notice and outage.
     */
    private function checkUnclassifiedNotices(SendAdminAlert $sendAdminAlert): void
    {
        Advisory::where('kind', AdvisoryKind::PreNotice)
            ->where('classification_failed_at', '<', now()->subMinutes(config('psm.notices.classifier.alert_after_minutes')))
            ->where('is_backfill', false)
            ->where('published_at', '>=', now()->subDays(config('psm.notices.relevant_days')))
            ->orderBy('id')
            ->each(fn (Advisory $advisory) => $sendAdminAlert->handle(
                "notice-unclassified:{$advisory->id}:{$advisory->classification_failed_at->getTimestamp()}",
                __('AI chưa phân loại được tin bảo mật'),
                [
                    __('Tin: :title', ['title' => $advisory->title]),
                    __('Tin vẫn được xử lý theo từ khoá và điểm CVSS. Kiểm tra ANTHROPIC_API_KEY và log lỗi.'),
                ],
                array_filter([__('Mở nguồn tin') => $advisory->url], filled(...)),
            ));
    }
}

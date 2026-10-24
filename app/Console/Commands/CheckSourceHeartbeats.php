<?php

namespace App\Console\Commands;

use App\Actions\SendAdminAlert;
use App\Models\SourceHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('psm:check-sources')]
#[Description('Alert the admin space about every data source that has not synced successfully within its threshold')]
class CheckSourceHeartbeats extends Command
{
    /**
     * Handover 5.1: staying silent because of an error is the worst failure. Each outage alerts once: the dedupe key
     * holds the last success, so the next outage of the same source alerts again.
     */
    public function handle(SendAdminAlert $sendAdminAlert): int
    {
        $stale = SourceHeartbeat::orderBy('source')->get()->filter(fn (SourceHeartbeat $heartbeat): bool => $heartbeat->isStale());

        foreach ($stale as $heartbeat) {
            $sendAdminAlert->handle(
                "source-stale:{$heartbeat->source}:".($heartbeat->last_success_at?->getTimestamp() ?? 'never'),
                __('Nguồn :source lỗi quá ngưỡng', ['source' => $heartbeat->name]),
                [
                    __('Thành công gần nhất: :time', ['time' => $heartbeat->last_success_at?->translatedFormat(__('d/m/Y H:i')) ?? __('chưa có')]),
                    __('Ngưỡng: :minutes phút', ['minutes' => $heartbeat->threshold_minutes]),
                    __('Lỗi gần nhất: :error', ['error' => $heartbeat->last_error ?? '—']),
                    __('Runtime và package phụ thuộc nguồn này được coi là Thiếu dữ liệu cho tới khi nguồn hoạt động lại.'),
                ],
            );
        }

        $this->components->info($stale->count().' stale sources.');

        return self::SUCCESS;
    }
}

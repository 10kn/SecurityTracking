<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Health of a technology's version catalog, from its source heartbeat.
 */
enum CatalogStatus: string implements HasLabel
{
    case Synced = 'synced';
    case LastSyncFailed = 'last_sync_failed';
    case Stale = 'stale';
    case NeverSynced = 'never_synced';

    public function getLabel(): string
    {
        return match ($this) {
            self::Synced => __('Đã đồng bộ'),
            self::LastSyncFailed => __('Lần đồng bộ gần nhất lỗi'),
            self::Stale => __('Lỗi quá ngưỡng, runtime thành Thiếu dữ liệu'),
            self::NeverSynced => __('Chưa đồng bộ'),
        };
    }

    /**
     * @return 'danger'|'warning'|'success'|'neutral'|'unknown'
     */
    public function dotTone(): string
    {
        return match ($this) {
            self::Synced => 'success',
            self::LastSyncFailed => 'warning',
            self::Stale => 'danger',
            self::NeverSynced => 'unknown',
        };
    }
}

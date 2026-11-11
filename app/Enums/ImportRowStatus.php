<?php

namespace App\Enums;

use App\Enums\Concerns\HasStatusDot;

/**
 * What confirming an import does with one row of the file (see ImportPlanner).
 */
enum ImportRowStatus: string
{
    use HasStatusDot;

    case NewProject = 'new_project';
    case ExistingProject = 'existing_project';
    case Skipped = 'skipped';
    case Invalid = 'invalid';

    public function getLabel(): string
    {
        return match ($this) {
            self::NewProject => __('Tạo kèm project mới'),
            self::ExistingProject => __('Thêm vào project có sẵn'),
            self::Skipped => __('Bỏ qua, service đã có'),
            self::Invalid => __('Lỗi'),
        };
    }

    /**
     * @return 'danger'|'warning'|'success'|'neutral'|'unknown'
     */
    public function dotTone(): string
    {
        return match ($this) {
            self::NewProject, self::ExistingProject => 'success',
            self::Skipped => 'neutral',
            self::Invalid => 'danger',
        };
    }

    /**
     * Whether confirming the import creates the service of the row.
     */
    public function createsService(): bool
    {
        return $this === self::NewProject || $this === self::ExistingProject;
    }
}

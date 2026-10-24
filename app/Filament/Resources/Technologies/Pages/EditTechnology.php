<?php

namespace App\Filament\Resources\Technologies\Pages;

use App\Filament\Resources\Technologies\TechnologyResource;
use App\Jobs\SyncTechnologyCatalogJob;
use App\Models\Technology;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * @property Technology $record
 */
class EditTechnology extends EditRecord
{
    protected static string $resource = TechnologyResource::class;

    /**
     * Runtimes restrict deleting their technology.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('Xóa công nghệ'))
                ->disabled(fn (Technology $record): bool => $record->runtimes()->exists())
                ->tooltip(fn (Technology $record): ?string => $record->runtimes()->exists()
                    ? __('Còn service đang dùng công nghệ này.')
                    : null),
        ];
    }

    /**
     * A different product or release source means a different catalog, so sync it now.
     */
    protected function afterSave(): void
    {
        if ($this->record->wasChanged(['endoflife_product', 'release_source'])) {
            SyncTechnologyCatalogJob::dispatch($this->record);
        }
    }
}

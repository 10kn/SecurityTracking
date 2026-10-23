<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use App\Models\Team;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * @property Team $record
 */
class EditTeam extends EditRecord
{
    protected static string $resource = TeamResource::class;

    public function getSubheading(): ?string
    {
        return __(':count project', ['count' => $this->record->projects()->count()]);
    }

    /**
     * Projects restrict deleting their team, so the button is disabled until they move to another team.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('Xóa team'))
                ->disabled(fn (Team $record): bool => $record->projects()->exists())
                ->tooltip(fn (Team $record): ?string => $record->projects()->exists()
                    ? __('Team còn project. Chuyển project sang team khác trước.')
                    : null),
        ];
    }
}

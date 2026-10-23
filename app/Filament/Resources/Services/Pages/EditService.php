<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Runtime;
use App\Models\Service;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property Service $record
 */
class EditService extends EditRecord
{
    use ManagesProductionInventory;

    protected static string $resource = ServiceResource::class;

    public function getSubheading(): ?string
    {
        return __('Project :project · team :team', [
            'project' => $this->record->project->name,
            'team' => $this->record->project->team->name,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('Xóa service'))
                ->modalDescription(__('Xóa service cũng xóa version runtime, lockfile và finding của service.'))
                ->successRedirectUrl(fn (Service $record): string => ProjectResource::getUrl('view', ['record' => $record->project_id])),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['runtime_versions'] = $this->record->productionDeployment?->runtimes
            ->mapWithKeys(fn (Runtime $runtime): array => [$runtime->language->value => $runtime->raw_version])
            ->all() ?? [];

        return $data;
    }

    /**
     * @param  Service  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Service
    {
        return DB::transaction(function () use ($record, $data): Service {
            $record->update($this->serviceAttributes($data));

            $this->saveProductionInventory($record, $data);

            return $record;
        });
    }

    protected function afterSave(): void
    {
        $this->record->unsetRelation('productionDeployment');
        $this->data['lockfile_uploads'] = [];
        $this->data['removed_lockfiles'] = [];
    }
}

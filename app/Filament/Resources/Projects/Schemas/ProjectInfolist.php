<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Support\StatusDot;
use App\Models\Project;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->schema([
                        TextEntry::make('repository_url')
                            ->label(__('Repository'))
                            ->fontFamily('mono')
                            ->url(fn (Project $record): ?string => Str::sanitizeUrl((string) $record->repository_url), shouldOpenInNewTab: true)
                            ->placeholder('—'),
                        TextEntry::make('team.name')
                            ->label(__('Team sở hữu'))
                            ->url(fn (Project $record): string => TeamResource::getUrl('edit', ['record' => $record->team])),
                        TextEntry::make('alert_channel')
                            ->label(__('Kênh cảnh báo'))
                            ->state(fn (Project $record): HtmlString => StatusDot::make(
                                __('Google Chat của team').' · '.$record->team->googleChatStatus()->getLabel(),
                                $record->team->googleChatStatus()->dotTone(),
                            )),
                        TextEntry::make('created_at')
                            ->label(__('Tạo lúc'))
                            ->date(__('d/m/Y')),
                    ]),
            ]);
    }
}

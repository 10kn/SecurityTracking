<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Filament\Resources\Teams\Schemas\TeamForm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProjectForm
{
    /**
     * An https:// URL or an scp-style git remote such as git@host:org/repo.git.
     */
    public const string REPOSITORY_URL_PATTERN = '/^(https:\/\/\S+|git@[^\s:]+:\S+)$/';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Thông tin chung'))
                    ->description(__('Finding của mọi service trong project được gửi tới team sở hữu.'))
                    ->aside()
                    ->columns(2)
                    ->schema([
                        Select::make('team_id')
                            ->label(__('Team sở hữu'))
                            ->relationship('team', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('name')
                            ->label(__('Tên project'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
                                if ($operation === 'create' && blank($get('slug'))) {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->label(__('Slug'))
                            ->required()
                            ->maxLength(255)
                            ->regex(TeamForm::SLUG_PATTERN)
                            ->validationMessages(['regex' => __('Chỉ dùng chữ thường, số và dấu gạch ngang.')])
                            ->unique(ignoreRecord: true)
                            ->extraInputAttributes(['class' => 'font-mono']),
                        TextInput::make('repository_url')
                            ->label(__('Repository URL'))
                            ->placeholder('https://git.example.com/team/project')
                            ->maxLength(255)
                            ->regex(self::REPOSITORY_URL_PATTERN)
                            ->validationMessages(['regex' => __('Dùng URL https:// hoặc git@host:org/repo.')])
                            ->columnSpanFull()
                            ->extraInputAttributes(['class' => 'font-mono']),
                    ]),
            ]);
    }
}

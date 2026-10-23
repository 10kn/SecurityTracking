<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\Ecosystem;
use App\Enums\Exposure;
use App\Enums\Language;
use App\Enums\ServiceKind;
use App\Filament\Resources\Teams\Schemas\TeamForm;
use App\Models\Lockfile;
use App\Models\Service;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ServiceForm
{
    /**
     * Version as found on the server, e.g. "8.3.12", "v20.11.0" or "8.3.12-1+deb.sury.org+1". M2 normalizes it.
     */
    public const string VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+~_-]*$/';

    /**
     * Upload limit per lockfile, in kilobytes. Large npm projects have package-lock.json files of several MB.
     */
    public const int MAX_LOCKFILE_KILOBYTES = 20480;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Thông tin service'))
                    ->description(__('Service thuộc một project; cảnh báo gửi tới team sở hữu project.'))
                    ->aside()
                    ->columns(2)
                    ->schema([
                        Select::make('project_id')
                            ->label(__('Project'))
                            ->relationship('project', 'name')
                            ->default(fn (): ?int => request()->integer('project') ?: null)
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('name')
                            ->label(__('Tên service'))
                            ->placeholder('shop-api')
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
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('project_id', $get('project_id')))
                            ->extraInputAttributes(['class' => 'font-mono']),
                        Select::make('kind')
                            ->label(__('Loại'))
                            ->options(ServiceKind::class)
                            ->default(ServiceKind::Server)
                            ->required(),
                        Select::make('exposure')
                            ->label(__('Phạm vi truy cập'))
                            ->options(Exposure::class)
                            ->default(Exposure::Internal)
                            ->required(),
                        TextInput::make('repository_path')
                            ->label(__('Thư mục trong repository'))
                            ->helperText(__('Dùng cho monorepo. Để "/" nếu service nằm ở gốc repository.'))
                            ->default('/')
                            ->required()
                            ->maxLength(255)
                            ->extraInputAttributes(['class' => 'font-mono'])
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Môi trường production'))
                    ->description(__('Nhập version runtime đang chạy và lockfile. Lưu xong, hệ thống đánh giá lại service này.'))
                    ->aside()
                    ->columns(3)
                    ->schema([
                        ...array_map(self::runtimeVersionInput(...), Language::cases()),
                        CheckboxList::make('removed_lockfiles')
                            ->label(__('Lockfile hiện có'))
                            ->helperText(__('Đánh dấu để xóa lockfile khi lưu.'))
                            ->options(fn (?Service $record): array => $record?->productionDeployment?->lockfiles
                                ->mapWithKeys(fn (Lockfile $lockfile): array => [
                                    $lockfile->path => $lockfile->path.' · '.__('tải lên :time', ['time' => $lockfile->uploaded_at->translatedFormat(__('d/m/Y H:i'))]),
                                ])
                                ->all() ?? [])
                            ->visible(fn (?Service $record): bool => $record?->productionDeployment?->lockfiles->isNotEmpty() ?? false)
                            ->columnSpanFull(),
                        FileUpload::make('lockfile_uploads')
                            ->label(__('Tải lockfile'))
                            ->helperText(__('composer.lock, Gemfile.lock hoặc package-lock.json. File cùng tên thay file cũ.'))
                            ->multiple()
                            ->storeFiles(false)
                            ->maxSize(self::MAX_LOCKFILE_KILOBYTES)
                            ->rules([fn (): Closure => self::lockfileNameRule()])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function runtimeVersionInput(Language $language): TextInput
    {
        return TextInput::make("runtime_versions.{$language->value}")
            ->label($language->getLabel())
            ->placeholder(match ($language) {
                Language::Php => '8.3.12',
                Language::Ruby => '3.3.6',
                Language::Nodejs => '22.11.0',
            })
            ->maxLength(64)
            ->regex(self::VERSION_PATTERN)
            ->validationMessages(['regex' => __('Version chỉ gồm chữ, số và . + ~ _ -')])
            ->extraInputAttributes(['class' => 'font-mono']);
    }

    private static function lockfileNameRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof TemporaryUploadedFile || Ecosystem::forLockfile($value->getClientOriginalName()) === null) {
                $fail(__('Chỉ nhận composer.lock, Gemfile.lock hoặc package-lock.json.'));
            }
        };
    }
}

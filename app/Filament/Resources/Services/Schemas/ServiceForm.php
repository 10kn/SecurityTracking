<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\Ecosystem;
use App\Enums\Exposure;
use App\Enums\ServiceKind;
use App\Filament\Resources\Teams\Schemas\TeamForm;
use App\Filament\Support\StatusDot;
use App\Models\Lockfile;
use App\Models\Service;
use App\Models\Technology;
use App\Services\RuntimeEvaluator;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
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
                    ->description(__('Chọn công nghệ, nhập version đang chạy và tải lockfile. Lưu xong, hệ thống đánh giá lại service này.'))
                    ->aside()
                    ->schema([
                        Repeater::make('runtimes')
                            ->label(__('Runtime / công nghệ'))
                            ->schema([
                                Select::make('technology_id')
                                    ->label(__('Công nghệ'))
                                    ->options(fn (): array => Technology::orderBy('name')->pluck('name', 'id')->all())
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->distinct()
                                    ->required()
                                    ->live(),
                                self::versionInput(),
                            ])
                            ->table([
                                TableColumn::make(__('Công nghệ'))->markAsRequired()->width('40%'),
                                TableColumn::make(__('Version'))->markAsRequired(),
                            ])
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel(__('Thêm công nghệ'))
                            ->columnSpanFull(),
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

    /**
     * Searchable list of catalog versions that also accepts the typed text: servers report versions such as
     * "8.3.12-1+deb.sury.org+1" that no catalog lists. Search runs on the server to offer the typed text, so the
     * debounce is short: the default waits a full second after each keystroke. The helper text shows how the
     * version will be evaluated before the form is saved.
     */
    private static function versionInput(): Select
    {
        return Select::make('raw_version')
            ->label(__('Version'))
            ->placeholder(__('Chọn version'))
            ->searchPrompt(__('Gõ để lọc hoặc nhập version khác'))
            ->options(fn (Get $get): array => self::versionOptions(self::technology($get)?->versionSuggestions() ?? []))
            ->searchable()
            ->searchDebounce(150)
            ->getSearchResultsUsing(fn (Get $get, string $search): array => self::versionSearchResults(self::technology($get), $search))
            ->getOptionLabelUsing(fn (?string $value): ?string => $value)
            ->required()
            ->rules(['max:64'])
            ->regex(self::VERSION_PATTERN)
            ->validationMessages(['regex' => __('Version chỉ gồm chữ, số và . + ~ _ -')])
            ->live()
            ->helperText(fn (Get $get, ?string $state): ?HtmlString => self::evaluationPreview(self::technology($get), $state))
            ->extraInputAttributes(['class' => 'font-mono']);
    }

    /**
     * @param  list<string>  $versions
     * @return array<string, string>
     */
    private static function versionOptions(array $versions): array
    {
        return array_combine($versions, $versions);
    }

    /**
     * Catalog versions starting with the typed text, led by the typed text itself when it is not one of them, e.g.
     * "8.3.12-1+deb.sury.org+1", so a version no catalog lists can still be chosen. The label is the plain version
     * because the select keeps showing it once chosen.
     *
     * @return array<string, string>
     */
    private static function versionSearchResults(?Technology $technology, string $search): array
    {
        $search = trim($search);
        $results = self::versionOptions($technology?->versionSuggestions(startingWith: $search) ?? []);

        if (! isset($results[$search]) && mb_strlen($search) <= 64 && preg_match(self::VERSION_PATTERN, $search)) {
            $results = [$search => $search] + $results;
        }

        return $results;
    }

    private static function technology(Get $get): ?Technology
    {
        return filled($get('technology_id')) ? Technology::find($get('technology_id')) : null;
    }

    /**
     * E.g. "● Thiếu bản vá bảo mật · Đã có bản vá bảo mật, bản mới nhất của nhánh là 8.3.14". When the typed text is not a plain
     * version, e.g. "8.3.12-1+deb.sury.org+1", it also says how it was read: "hiểu là 8.3.12, nhánh 8.3".
     */
    private static function evaluationPreview(?Technology $technology, ?string $rawVersion): ?HtmlString
    {
        if ($technology === null || blank($rawVersion)) {
            return null;
        }

        $evaluation = (new RuntimeEvaluator)->evaluate($technology, $rawVersion);
        $details = [$evaluation->reasonText()];

        if ($evaluation->version !== null && $evaluation->branch !== null && $evaluation->version !== trim($rawVersion)) {
            $details[] = __('hiểu là :version, nhánh :branch', ['version' => $evaluation->version, 'branch' => $evaluation->branch]);
        }

        return new HtmlString(StatusDot::make($evaluation->status->getLabel(), $evaluation->status->dotTone())->toHtml()
            .' <span class="psm-muted">· '.e(implode(' · ', $details)).'</span>');
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

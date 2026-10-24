<?php

namespace App\Filament\Resources\Technologies\Schemas;

use App\Enums\ReleaseSource;
use App\Filament\Resources\Teams\Schemas\TeamForm;
use App\Services\Catalog\EndoflifeDateClient;
use Closure;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Throwable;

class TechnologyForm
{
    /**
     * endoflife.date product ids, e.g. "php", "nodejs", "amazon-linux", "dotnet".
     */
    public const string PRODUCT_PATTERN = '/^[a-z0-9][a-z0-9.+-]*$/';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Công nghệ'))
                    ->description(__('Version và ngày hết hỗ trợ lấy từ endoflife.date, tự cập nhật mỗi giờ.'))
                    ->aside()
                    ->columns(2)
                    ->schema([
                        TextInput::make('endoflife_product')
                            ->label(__('Mã sản phẩm trên endoflife.date'))
                            ->placeholder('python')
                            ->helperText(__('Ví dụ: python, go, mysql, postgresql, nginx, laravel, rails.'))
                            ->required()
                            ->maxLength(100)
                            ->regex(self::PRODUCT_PATTERN)
                            ->unique(ignoreRecord: true)
                            ->rules([fn (): Closure => self::productExistsRule()])
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set, ?string $state) => self::fillNameFromProduct($get, $set, $state))
                            ->extraInputAttributes(['class' => 'font-mono'])
                            ->columnSpanFull(),
                        TextInput::make('name')
                            ->label(__('Tên hiển thị'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label(__('Slug'))
                            ->required()
                            ->maxLength(255)
                            ->regex(TeamForm::SLUG_PATTERN)
                            ->validationMessages(['regex' => __('Chỉ dùng chữ thường, số và dấu gạch ngang.')])
                            ->unique(ignoreRecord: true)
                            ->extraInputAttributes(['class' => 'font-mono']),
                        Radio::make('release_source')
                            ->label(__('Nguồn danh sách bản phát hành'))
                            ->options(ReleaseSource::class)
                            ->default(ReleaseSource::Endoflife)
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Suggests the display name and slug from endoflife.date, e.g. "Node.js" for "nodejs", without overwriting what the admin typed.
     */
    private static function fillNameFromProduct(Get $get, Set $set, ?string $product): void
    {
        if (blank($product) || preg_match(self::PRODUCT_PATTERN, $product) !== 1) {
            return;
        }

        if (blank($get('slug'))) {
            $set('slug', Str::slug($product));
        }

        if (filled($get('name'))) {
            return;
        }

        try {
            $set('name', app(EndoflifeDateClient::class)->productLabel($product) ?? '');
        } catch (Throwable) {
            // The validation rule reports an unreachable endoflife.date when the form is saved.
        }
    }

    private static function productExistsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match(self::PRODUCT_PATTERN, $value) !== 1) {
                return;
            }

            try {
                $exists = app(EndoflifeDateClient::class)->productLabel($value) !== null;
            } catch (Throwable) {
                $fail(__('Không kết nối được endoflife.date để kiểm tra mã sản phẩm. Thử lại sau.'));

                return;
            }

            if (! $exists) {
                $fail(__('endoflife.date không có sản phẩm ":product".', ['product' => $value]));
            }
        };
    }
}

<?php

namespace App\Filament\Resources\Teams\Schemas;

use App\Actions\SendTestChatMessage;
use App\Filament\Support\StatusDot;
use App\Models\Team;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class TeamForm
{
    /**
     * Lowercase letters, digits and single hyphens, e.g. "internal-tools".
     */
    public const string SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Thông tin chung'))
                    ->description(__('Slug dùng trong URL. Đổi slug làm hỏng link cũ trong tin Chat.'))
                    ->aside()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Tên team'))
                            ->placeholder('Payments')
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
                            ->regex(self::SLUG_PATTERN)
                            ->validationMessages(['regex' => __('Chỉ dùng chữ thường, số và dấu gạch ngang.')])
                            ->unique(ignoreRecord: true)
                            ->extraInputAttributes(['class' => 'font-mono']),
                    ]),
                Section::make(__('Google Chat'))
                    ->description(__('Kênh cảnh báo chính. Một lỗ hổng ảnh hưởng nhiều service chỉ gửi một tin.'))
                    ->aside()
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('webhook')
                            ->label(__('Webhook URL'))
                            ->state(fn (Team $record): ?string => $record->maskedGoogleChatWebhookUrl())
                            ->placeholder(__('Chưa có webhook. Team chưa nhận được cảnh báo.'))
                            ->fontFamily('mono'),
                        TextEntry::make('status')
                            ->hiddenLabel()
                            ->state(fn (Team $record): HtmlString => self::statusLine($record))
                            ->visible(fn (Team $record): bool => filled($record->google_chat_webhook_url)),
                        Actions::make([
                            self::sendTestMessageAction(),
                            self::replaceWebhookAction(),
                        ])->key('google_chat_actions'),
                    ]),
            ]);
    }

    /**
     * E.g. "● Hoạt động · tin gần nhất 23/10/2026 08:00".
     */
    private static function statusLine(Team $record): HtmlString
    {
        $status = StatusDot::make($record->googleChatStatus()->getLabel(), $record->googleChatStatus()->dotTone());
        $latest = $record->latestChatNotification;

        if ($latest === null) {
            return $status;
        }

        $detail = $latest->failed_at !== null
            ? __('lỗi lúc :time: :error', ['time' => $latest->failed_at->translatedFormat(__('d/m/Y H:i')), 'error' => $latest->last_error])
            : __('tin gần nhất :time', ['time' => $latest->sent_at?->translatedFormat(__('d/m/Y H:i'))]);

        return new HtmlString($status->toHtml().' <span class="psm-muted">· '.e($detail).'</span>');
    }

    private static function sendTestMessageAction(): Action
    {
        return Action::make('sendTestMessage')
            ->label(__('Gửi thử'))
            ->color('gray')
            ->visible(fn (Team $record): bool => filled($record->google_chat_webhook_url))
            ->action(function (Team $record, SendTestChatMessage $sendTestChatMessage): void {
                $error = $sendTestChatMessage->handle($record);

                $record->unsetRelation('latestChatNotification');

                if ($error !== null) {
                    Notification::make()->danger()->title(__('Gửi thử thất bại'))->body($error)->send();

                    return;
                }

                Notification::make()->success()->title(__('Gửi thử thành công.'))->send();
            });
    }

    /**
     * Sends a test message to the new URL first and saves it only when Google Chat accepts the message.
     */
    private static function replaceWebhookAction(): Action
    {
        return Action::make('replaceWebhook')
            ->label(fn (Team $record): string => filled($record->google_chat_webhook_url) ? __('Thay') : __('Thêm webhook'))
            ->color('gray')
            ->modalHeading(__('Webhook Google Chat'))
            ->modalDescription(__('Trong Google Chat: Space → Apps & integrations → Webhooks.'))
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('Gửi thử & lưu'))
            ->schema([
                TextInput::make('google_chat_webhook_url')
                    ->label(__('Webhook URL'))
                    ->placeholder(Team::GOOGLE_CHAT_WEBHOOK_PREFIX.'…')
                    ->required()
                    ->maxLength(2048)
                    ->url()
                    ->startsWith([Team::GOOGLE_CHAT_WEBHOOK_PREFIX])
                    ->validationMessages(['starts_with' => __('URL phải bắt đầu bằng https://chat.googleapis.com/v1/spaces/')])
                    ->helperText(__('Hệ thống gửi một tin thử trước khi lưu. Sau đó URL chỉ hiện một phần.'))
                    ->extraInputAttributes(['class' => 'font-mono']),
            ])
            ->action(function (array $data, Team $record, Action $action, SendTestChatMessage $sendTestChatMessage): void {
                $error = $sendTestChatMessage->handle($record, $data['google_chat_webhook_url']);

                if ($error !== null) {
                    Notification::make()->danger()->title(__('Gửi thử thất bại, webhook chưa được lưu'))->body($error)->send();

                    $action->halt();
                }

                $record->update(['google_chat_webhook_url' => $data['google_chat_webhook_url']]);
                $record->unsetRelation('latestChatNotification');

                Notification::make()->success()->title(__('Đã lưu webhook và gửi thử thành công.'))->send();
            });
    }
}

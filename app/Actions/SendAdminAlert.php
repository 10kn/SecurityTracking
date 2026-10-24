<?php

namespace App\Actions;

use App\Enums\ChatNotificationKind;
use App\Models\ChatNotification;
use App\Services\GoogleChatCards;
use Illuminate\Support\Traits\Localizable;

/**
 * Tells the admin space about a system error (handover D7: it replaces the backup email).
 */
class SendAdminAlert
{
    use Localizable;

    public function __construct(private QueueChatNotification $queueChatNotification, private GoogleChatCards $cards) {}

    /**
     * @param  string  $dedupeKey  The same key never alerts twice, e.g. one key per outage of a source.
     * @param  list<string>  $lines
     * @param  array<string, string>  $links  Button text => URL.
     */
    public function handle(string $dedupeKey, string $title, array $lines, array $links = []): ?ChatNotification
    {
        $payload = $this->withLocale(config('psm.alerts.locale'), fn (): array => $this->cards->adminAlert($title, $lines, $links));

        return $this->queueChatNotification->handle(null, ChatNotificationKind::AdminAlert, "admin:{$dedupeKey}", $payload);
    }
}

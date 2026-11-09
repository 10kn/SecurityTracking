<?php

namespace App\Enums;

/**
 * The admin's two Google Chat spaces, set on the "Cài đặt hệ thống" screen or in the .env: system errors, and vendor
 * security news (the system errors space when it is not set).
 */
enum AdminWebhook: string
{
    case Admin = 'admin';
    case News = 'news';

    /**
     * Its name in SystemSettings.
     */
    public function settingName(): string
    {
        return match ($this) {
            self::Admin => 'admin_webhook',
            self::News => 'news_webhook',
        };
    }

    public function testMessage(): string
    {
        return match ($this) {
            self::Admin => __('Tin thử từ Security Manager: space này sẽ nhận cảnh báo lỗi hệ thống.'),
            self::News => __('Tin thử từ Security Manager: space này sẽ nhận tin bảo mật từ vendor.'),
        };
    }
}

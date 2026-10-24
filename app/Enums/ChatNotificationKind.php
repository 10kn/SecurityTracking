<?php

namespace App\Enums;

enum ChatNotificationKind: string
{
    case Test = 'test';
    case Immediate = 'immediate';
    case PreNotice = 'pre_notice';
    case Digest = 'digest';
    case AdminAlert = 'admin_alert';

    public function getLabel(): string
    {
        return match ($this) {
            self::Test => __('Gửi thử'),
            self::Immediate => __('Gửi ngay'),
            self::PreNotice => __('Cảnh báo chuẩn bị'),
            self::Digest => __('Bản tổng hợp'),
            self::AdminAlert => __('Cảnh báo admin'),
        };
    }
}

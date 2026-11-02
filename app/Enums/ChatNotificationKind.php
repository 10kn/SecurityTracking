<?php

namespace App\Enums;

enum ChatNotificationKind: string
{
    case Test = 'test';
    case Immediate = 'immediate';
    case PreNotice = 'pre_notice';
    case IncidentUpdate = 'incident_update';
    case Digest = 'digest';
    case AdminAlert = 'admin_alert';
    case SecurityNews = 'security_news';
    case ProjectResumed = 'project_resumed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Test => __('Gửi thử'),
            self::Immediate => __('Gửi ngay'),
            self::PreNotice => __('Cảnh báo chuẩn bị'),
            self::IncidentUpdate => __('Cập nhật sự kiện'),
            self::Digest => __('Bản tổng hợp'),
            self::AdminAlert => __('Cảnh báo admin'),
            self::SecurityNews => __('Tin bảo mật'),
            self::ProjectResumed => __('Theo dõi lại project'),
        };
    }
}

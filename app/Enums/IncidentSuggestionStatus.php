<?php

namespace App\Enums;

enum IncidentSuggestionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Chờ admin'),
            self::Accepted => __('Đã gộp'),
            self::Rejected => __('Đã bỏ qua'),
        };
    }
}

<?php

namespace App\Enums;

use App\Models\User;

enum Theme: string
{
    case Graphite = 'graphite';
    case Ocean = 'ocean';
    case Forest = 'forest';
    case Night = 'night';

    /**
     * The signed-in user's theme, or the default for guests (e.g. the login page).
     */
    public static function current(): self
    {
        $user = auth()->user();

        return $user instanceof User ? $user->theme : self::Graphite;
    }

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Graphite => __('Trắng – đen, ít màu nhất'),
            self::Ocean => __('Nhấn xanh dương'),
            self::Forest => __('Nhấn teal, nền ấm'),
            self::Night => __('Giao diện tối'),
        };
    }

    /**
     * The accent color shown as a swatch in the appearance menu.
     */
    public function swatch(): string
    {
        return match ($this) {
            self::Graphite => '#18181b',
            self::Ocean => '#2563eb',
            self::Forest => '#0f766e',
            self::Night => '#4f46e5',
        };
    }
}

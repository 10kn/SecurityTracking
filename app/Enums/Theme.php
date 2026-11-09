<?php

namespace App\Enums;

use App\Models\User;

enum Theme: string
{
    case Graphite = 'graphite';
    case Ocean = 'ocean';
    case Forest = 'forest';
    case Night = 'night';
    case System = 'system';

    /**
     * The signed-in user's theme, or the default for guests (e.g. the login page).
     */
    public static function current(): self
    {
        $user = auth()->user();

        return $user instanceof User ? $user->theme : self::Ocean;
    }

    /**
     * The light theme the signed-in user shows while following the system and the system is light.
     */
    public static function currentSystemLight(): self
    {
        $user = auth()->user();

        return $user instanceof User ? $user->system_light_theme : self::Ocean;
    }

    /**
     * The theme rendered on <html>. "System" renders its light theme; a script in <head> swaps in Night before the first
     * paint when the system is dark.
     */
    public static function rendered(): self
    {
        $theme = self::current();

        return $theme === self::System ? self::currentSystemLight() : $theme;
    }

    /**
     * The themes "System" can show when the system is light. Night is the only dark theme, so it is the dark side.
     *
     * @return list<self>
     */
    public static function lightThemes(): array
    {
        return [self::Graphite, self::Ocean, self::Forest];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::System => __('Theo hệ thống'),
            default => ucfirst($this->value),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Graphite => __('Trắng – đen, ít màu nhất'),
            self::Ocean => __('Nhấn xanh dương'),
            self::Forest => __('Nhấn teal, nền ấm'),
            self::Night => __('Giao diện tối'),
            self::System => __('Sáng hoặc tối theo hệ điều hành'),
        };
    }

    /**
     * The accent color shown as a swatch in the appearance menu. "System" is half light, half Night.
     */
    public function swatch(): string
    {
        return match ($this) {
            self::Graphite => '#18181b',
            self::Ocean => '#2563eb',
            self::Forest => '#0f766e',
            self::Night => '#4f46e5',
            self::System => 'linear-gradient(135deg, #ffffff 50%, #27272a 50%)',
        };
    }
}

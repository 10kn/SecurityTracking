<?php

namespace App\Enums;

use App\Models\User;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum Theme: string implements HasDescription, HasLabel
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

    public function isDark(): bool
    {
        return $this === self::Night;
    }

    /**
     * Filament palettes for this theme.
     *
     * Filament paints solid buttons with shade 600 and hovers with 500. Graphite and Forest
     * remap those shades so the primary button is zinc-900 / teal-700, as in prototype v2.
     *
     * @return array{primary: array<int, string>, gray: array<int, string>}
     */
    public function colors(): array
    {
        return match ($this) {
            self::Graphite => [
                'primary' => array_replace(Color::Zinc, [500 => Color::Zinc[700], 600 => Color::Zinc[900]]),
                'gray' => Color::Zinc,
            ],
            self::Ocean => [
                'primary' => Color::Blue,
                'gray' => Color::Slate,
            ],
            self::Forest => [
                'primary' => array_replace(Color::Teal, [500 => Color::Teal[800], 600 => Color::Teal[700]]),
                'gray' => Color::Stone,
            ],
            self::Night => [
                'primary' => Color::Indigo,
                'gray' => Color::Zinc,
            ],
        };
    }
}

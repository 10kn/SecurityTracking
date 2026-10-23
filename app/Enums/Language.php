<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Language: string implements HasLabel
{
    case Php = 'php';
    case Ruby = 'ruby';
    case Nodejs = 'nodejs';

    public function getLabel(): string
    {
        return match ($this) {
            self::Php => 'PHP',
            self::Ruby => 'Ruby',
            self::Nodejs => 'Node.js',
        };
    }

    /**
     * The release branch a version belongs to, e.g. "8.3" for PHP 8.3.12 and "20" for Node.js 20.11.0.
     */
    public function branchOf(string $version): string
    {
        $parts = explode('.', ltrim($version, 'v'));

        return $this === self::Nodejs ? $parts[0] : implode('.', array_slice($parts, 0, 2));
    }
}

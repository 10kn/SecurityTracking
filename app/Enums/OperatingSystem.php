<?php

namespace App\Enums;

/**
 * The operating system a deployment runs on, and those a vendor's news says it is limited to (see
 * Incident::scopeFor()).
 */
enum OperatingSystem: string
{
    case Linux = 'linux';
    case Windows = 'windows';
    case MacOs = 'macos';

    public function getLabel(): string
    {
        return match ($this) {
            self::Linux => 'Linux',
            self::Windows => 'Windows',
            self::MacOs => 'macOS',
        };
    }

    /**
     * E.g. "Windows" or "Windows, macOS".
     *
     * @param  list<self>  $systems
     */
    public static function labels(array $systems): string
    {
        return collect($systems)->map(fn (self $system): string => $system->getLabel())->join(', ');
    }
}

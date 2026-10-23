<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Draws the user's initials as an inline SVG. Filament's default provider loads the image from ui-avatars.com,
 * which would send every admin's name to a third party.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->map(fn (string $word): string => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word), 0, 1))
            ->filter()
            ->take(2)
            ->join('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#52525b"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#ffffff" font-family="Inter, sans-serif" font-size="26" font-weight="600">'
            .htmlspecialchars(mb_strtoupper($initials), ENT_XML1)
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}

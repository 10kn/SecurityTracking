<?php

namespace App\Http\Middleware;

use App\Enums\Theme;
use App\Models\User;
use Closure;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the signed-in user's locale and theme palette. Guests get the defaults (Vietnamese, Graphite).
 */
class ApplyUserPreferences
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            App::setLocale($user->locale->value);
        }

        FilamentColor::register(fn (): array => Theme::current()->colors());

        return $next($request);
    }
}

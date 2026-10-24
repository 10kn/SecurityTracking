<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use App\Enums\Theme;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Changing the theme or the language reloads the whole page, so the props that depend on them are sent once.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user instanceof User ? [
                    'name' => $user->name,
                    'email' => $user->email,
                    'initials' => $user->initials(),
                ] : null,
            ],
            'preferences' => [
                'theme' => Theme::current()->value,
                'locale' => App::getLocale(),
            ],
            'themes' => Inertia::once(fn (): array => array_map(fn (Theme $theme): array => [
                'value' => $theme->value,
                'label' => $theme->getLabel(),
                'description' => $theme->getDescription(),
                'swatch' => $theme->swatch(),
            ], Theme::cases())),
            'locales' => Inertia::once(fn (): array => array_map(fn (Locale $locale): array => [
                'value' => $locale->value,
                'label' => $locale->shortLabel(),
            ], Locale::cases())),
            'translations' => Inertia::once(fn (): ?array => $this->translations()),
        ];
    }

    /**
     * Strings are written in Vietnamese and translated by lang/{locale}.json, as on the server.
     *
     * @return array<string, string>|null
     */
    private function translations(): ?array
    {
        $locale = App::getLocale();

        if ($locale === Locale::Vietnamese->value) {
            return null;
        }

        return app('translator')->getLoader()->load($locale, '*', '*');
    }
}

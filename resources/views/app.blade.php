<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ \App\Enums\Theme::rendered()->value }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name') }}</title>

        @if (\App\Enums\Theme::current() === \App\Enums\Theme::System)
            {{-- Swaps in Night before the first paint when the system is dark; resources/js/lib/theme.ts follows later changes. --}}
            <script>
                if (matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.dataset.theme = 'night';
            </script>
        @endif

        @if (app()->getLocale() === 'ja')
            @vite('resources/css/japanese.css')
        @endif
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head />
    </head>
    <body>
        <x-inertia::app />
    </body>
</html>

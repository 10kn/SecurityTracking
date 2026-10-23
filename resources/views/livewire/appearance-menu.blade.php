@php
    use App\Enums\Locale;
    use App\Enums\Theme;
    use Filament\Support\Icons\Heroicon;
@endphp

<div class="psm-appearance">
    <x-filament::dropdown placement="bottom-end" width="xs">
        <x-slot name="trigger">
            <button type="button" class="psm-appearance-trigger" aria-label="{{ __('Giao diện') }}">
                <span class="psm-theme-dot" style="background-color: {{ $currentTheme->swatch() }}"></span>
                <span class="psm-appearance-label">{{ __('Giao diện') }}</span>
            </button>
        </x-slot>

        <x-filament::dropdown.list>
            @foreach (Theme::cases() as $theme)
                <x-filament::dropdown.list.item
                    wire:click="setTheme('{{ $theme->value }}')"
                    role="menuitemradio"
                    :aria-checked="$theme === $currentTheme ? 'true' : 'false'"
                    :icon="$theme === $currentTheme ? Heroicon::Check : null"
                    :class="$theme === $currentTheme ? 'psm-theme-option psm-theme-option-current' : 'psm-theme-option'"
                >
                    <span class="psm-theme-option-body">
                        <span class="psm-theme-dot" style="background-color: {{ $theme->swatch() }}"></span>
                        <span>
                            <span class="psm-theme-option-label">{{ $theme->getLabel() }}</span>
                            <small>{{ $theme->getDescription() }}</small>
                        </span>
                    </span>
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>

    <div class="psm-lang-switch" role="group" aria-label="{{ __('Ngôn ngữ') }}">
        @foreach (Locale::cases() as $locale)
            <button
                type="button"
                lang="{{ $locale->value }}"
                aria-pressed="{{ $locale === $currentLocale ? 'true' : 'false' }}"
                wire:click="setLocale('{{ $locale->value }}')"
            >
                {{ $locale->shortLabel() }}
            </button>
        @endforeach
    </div>
</div>

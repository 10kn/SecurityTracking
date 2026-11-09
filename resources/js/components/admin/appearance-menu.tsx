import { router, usePage } from '@inertiajs/react';

import { update as updatePreferences } from '@/actions/App/Http/Controllers/Admin/PreferenceController';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuLabel, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/lib/i18n';
import { applyTheme, useSystemTheme } from '@/lib/theme';
import { cn } from '@/lib/utils';

const lightThemes = ['graphite', 'ocean', 'forest'];

/**
 * The 4 themes plus "System", saved on the user. Colors are CSS variables keyed by data-theme on <html>, so the switch is
 * instant and the save runs in the background. "System" shows Night while the OS is dark and one of the 3 light themes,
 * picked below the list, while it is light.
 */
export function AppearanceMenu() {
    const t = useTranslation();
    const { preferences, themes } = usePage().props;

    useSystemTheme(preferences.theme, preferences.system_light_theme);

    const save = (data: { theme: string } | { system_light_theme: string }) => router.put(updatePreferences.url(), data, { preserveScroll: true, preserveState: true });

    const setTheme = (theme: string) => {
        applyTheme(theme, preferences.system_light_theme);
        save({ theme });
    };

    const setSystemLightTheme = (systemLightTheme: string) => {
        applyTheme('system', systemLightTheme);
        save({ system_light_theme: systemLightTheme });
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="secondary" className="gap-2 max-md:w-9 max-md:px-0" aria-label={t('Giao diện')}>
                    <i className="block size-3.5 shrink-0 rounded-full border border-border-strong bg-primary" aria-hidden />
                    <span className="max-md:hidden">{t('Giao diện')}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent aria-label={t('Giao diện')}>
                <DropdownMenuRadioGroup value={preferences.theme} onValueChange={setTheme}>
                    {themes.map((theme) => (
                        // Choosing "System" keeps the menu open so the light theme can be picked right away.
                        <DropdownMenuRadioItem key={theme.value} value={theme.value} onSelect={theme.value === 'system' ? (event) => event.preventDefault() : undefined}>
                            <i className="block size-3.5 shrink-0 rounded-full border border-border-strong" style={{ background: theme.swatch }} aria-hidden />
                            <span className="grid min-w-0 flex-1">
                                <span>{theme.label}</span>
                                <small className="text-xs text-muted-foreground">{theme.description}</small>
                            </span>
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
                {preferences.theme === 'system' && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel>{t('Theme khi hệ thống ở chế độ sáng')}</DropdownMenuLabel>
                        <DropdownMenuRadioGroup value={preferences.system_light_theme} onValueChange={setSystemLightTheme}>
                            {themes
                                .filter((theme) => lightThemes.includes(theme.value))
                                .map((theme) => (
                                    <DropdownMenuRadioItem key={theme.value} value={theme.value} className="py-1.5">
                                        <i className="block size-3.5 shrink-0 rounded-full border border-border-strong" style={{ background: theme.swatch }} aria-hidden />
                                        <span className="min-w-0 flex-1">{theme.label}</span>
                                    </DropdownMenuRadioItem>
                                ))}
                        </DropdownMenuRadioGroup>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** VI / 日本語. Translations and the type scale are applied while the page renders, so it reloads after saving. */
export function LanguageSwitch() {
    const t = useTranslation();
    const { preferences, locales } = usePage().props;

    return (
        <div className="inline-flex h-9 rounded-md border border-border bg-surface p-[3px]" role="group" aria-label={t('Ngôn ngữ')}>
            {locales.map((locale) => (
                <button
                    key={locale.value}
                    type="button"
                    lang={locale.value}
                    aria-pressed={preferences.locale === locale.value}
                    onClick={() =>
                        preferences.locale !== locale.value &&
                        router.put(updatePreferences.url(), { locale: locale.value }, { preserveScroll: true, onSuccess: () => window.location.reload() })
                    }
                    className={cn(
                        'rounded-sm px-2.5 text-sm font-medium whitespace-nowrap text-muted-foreground',
                        locale.value === 'ja' && "font-['Noto_Sans_JP_Variable','Hiragino_Sans','Yu_Gothic_UI',sans-serif]",
                        preferences.locale === locale.value && 'bg-surface-2 text-foreground',
                    )}
                >
                    {locale.label}
                </button>
            ))}
        </div>
    );
}

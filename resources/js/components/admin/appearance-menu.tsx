import { router, usePage } from '@inertiajs/react';

import { update as updatePreferences } from '@/actions/App/Http/Controllers/Admin/PreferenceController';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * The 4 themes, saved on the user. Colors are CSS variables keyed by data-theme on <html>, so the switch is instant and
 * the save runs in the background.
 */
export function AppearanceMenu() {
    const t = useTranslation();
    const { preferences, themes } = usePage().props;

    const setTheme = (theme: string) => {
        document.documentElement.dataset.theme = theme;
        router.put(updatePreferences.url(), { theme }, { preserveScroll: true, preserveState: true });
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
                        <DropdownMenuRadioItem key={theme.value} value={theme.value}>
                            <i className="block size-3.5 shrink-0 rounded-full border border-border-strong" style={{ background: theme.swatch }} aria-hidden />
                            <span className="grid min-w-0 flex-1">
                                <span>{theme.label}</span>
                                <small className="text-xs text-muted-foreground">{theme.description}</small>
                            </span>
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
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

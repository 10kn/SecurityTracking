import { useEffect } from 'react';

const darkScheme = () => window.matchMedia('(prefers-color-scheme: dark)');

/** The data-theme to render. "system" is Night while the OS is dark, else the light theme chosen for it (as the script in app.blade.php does on load). */
export function resolveTheme(theme: string, systemLightTheme: string): string {
    if (theme !== 'system') {
        return theme;
    }

    return darkScheme().matches ? 'night' : systemLightTheme;
}

export function applyTheme(theme: string, systemLightTheme: string): void {
    document.documentElement.dataset.theme = resolveTheme(theme, systemLightTheme);
}

/** Follows the OS switching between light and dark while "system" is chosen, without a reload. */
export function useSystemTheme(theme: string, systemLightTheme: string): void {
    useEffect(() => {
        if (theme !== 'system') {
            return;
        }

        const media = darkScheme();
        const apply = () => applyTheme(theme, systemLightTheme);

        apply();
        media.addEventListener('change', apply);

        return () => media.removeEventListener('change', apply);
    }, [theme, systemLightTheme]);
}

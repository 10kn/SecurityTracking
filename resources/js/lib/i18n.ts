import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

export type Replacements = Record<string, string | number>;

/**
 * Strings are written in Vietnamese, as on the server, and translated by lang/{locale}.json. Placeholders such as
 * ":count" are replaced like Laravel's __().
 */
export function translate(translations: Record<string, string> | null, key: string, replacements: Replacements = {}): string {
    const text = translations?.[key] ?? key;

    return Object.entries(replacements)
        .sort(([a], [b]) => b.length - a.length)
        .reduce((result, [name, value]) => result.replaceAll(`:${name}`, String(value)), text);
}

export function useTranslation(): (key: string, replacements?: Replacements) => string {
    const { translations } = usePage().props;

    return useCallback((key: string, replacements?: Replacements) => translate(translations, key, replacements), [translations]);
}

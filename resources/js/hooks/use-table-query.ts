import { router } from '@inertiajs/react';
import { useCallback } from 'react';

import type { TableState } from '@/types';

type QueryValue = string | number | null | undefined;

/**
 * Reloads only the given props of the current page with a changed search, sort, filter or page. Keeps the scroll
 * position and the component state, and replaces the history entry so Back leaves the page instead of undoing a filter.
 */
export function useTableQuery(table: TableState, only: string[]) {
    return useCallback(
        (changes: Record<string, QueryValue>, resetPage = true) => {
            const current: Record<string, QueryValue> = {
                search: table.search,
                sort: table.sort,
                per_page: table.per_page,
                page: new URLSearchParams(window.location.search).get('page'),
            };

            // Filters such as `team` sit next to the search and sort.
            for (const [key, value] of Object.entries(table as unknown as Record<string, unknown>)) {
                if (!(key in current) && key !== 'per_page_options' && (typeof value === 'string' || typeof value === 'number' || value === null)) {
                    current[key] = value;
                }
            }

            const query = Object.fromEntries(
                Object.entries({ ...current, ...(resetPage ? { page: null } : {}), ...changes }).filter(([, value]) => value !== null && value !== undefined && value !== ''),
            );

            router.get(window.location.pathname, query, { preserveState: true, preserveScroll: true, replace: true, only: [...only, 'table'] });
        },
        [table, only],
    );
}

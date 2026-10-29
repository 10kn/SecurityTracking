import { router } from '@inertiajs/react';
import { useCallback, useRef } from 'react';

import type { TableState } from '@/types';

type QueryValue = string | number | null | undefined;

/** The search, sort, filters and page the table shows now, read from its props and the URL. */
function queryOf(table: TableState): Record<string, QueryValue> {
    const query: Record<string, QueryValue> = {
        search: table.search,
        sort: table.sort,
        per_page: table.per_page,
        page: new URLSearchParams(window.location.search).get('page'),
    };

    // Filters such as `team` sit next to the search and sort.
    for (const [key, value] of Object.entries(table as unknown as Record<string, unknown>)) {
        if (!(key in query) && key !== 'per_page_options' && (typeof value === 'string' || typeof value === 'number' || value === null)) {
            query[key] = value;
        }
    }

    return query;
}

/**
 * Reloads only the given props of the current page with a changed search, sort, filter or page. Keeps the scroll
 * position and the component state, and replaces the history entry so Back leaves the page instead of undoing a filter.
 * A change made while a reload is still on its way, e.g. a filter right after a search or typing right after "Bỏ lọc",
 * builds on that reload instead of on the props it is about to replace.
 */
export function useTableQuery(table: TableState, only: string[]) {
    const reloadingRef = useRef<Record<string, QueryValue> | null>(null);

    return useCallback(
        (changes: Record<string, QueryValue>, resetPage = true) => {
            const next = { ...(reloadingRef.current ?? queryOf(table)), ...(resetPage ? { page: null } : {}), ...changes };

            reloadingRef.current = next;

            router.get(
                window.location.pathname,
                Object.fromEntries(Object.entries(next).filter(([, value]) => value !== null && value !== undefined && value !== '')),
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: [...only, 'table'],
                    // Landed, failed or interrupted: the props are the truth again, unless a newer reload has taken over.
                    onFinish: () => {
                        if (reloadingRef.current === next) {
                            reloadingRef.current = null;
                        }
                    },
                },
            );
        },
        [table, only],
    );
}

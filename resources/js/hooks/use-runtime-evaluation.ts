import { useEffect, useState } from 'react';

import RuntimeEvaluationController from '@/actions/App/Http/Controllers/Admin/RuntimeEvaluationController';
import { getJson } from '@/lib/http';
import type { StatusDotData } from '@/types';

export interface RuntimeEvaluationPreview {
    status: StatusDotData;
    details: string;
}

/** Answers are the same until the catalog syncs, so the page keeps them while it is open. */
const cache = new Map<string, RuntimeEvaluationPreview>();

/**
 * How the server would evaluate the version, e.g. "● Thiếu bản vá bảo mật · Đã có bản vá bảo mật 8.3.14", fetched
 * 150 ms after the version stops changing.
 */
export function useRuntimeEvaluation(technology: string | null, version: string): RuntimeEvaluationPreview | null {
    const key = technology === null || version.trim() === '' ? null : `${technology}:${version.trim()}`;
    const [preview, setPreview] = useState<RuntimeEvaluationPreview | null>(key === null ? null : (cache.get(key) ?? null));

    useEffect(() => {
        if (key === null || technology === null) {
            setPreview(null);

            return;
        }

        const cached = cache.get(key);

        if (cached) {
            setPreview(cached);

            return;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(() => {
            getJson<RuntimeEvaluationPreview>(RuntimeEvaluationController.url({ query: { technology, version: version.trim() } }), controller.signal)
                .then((response) => {
                    cache.set(key, response);
                    setPreview(response);
                })
                .catch(() => undefined);
        }, 150);

        return () => {
            window.clearTimeout(timeout);
            controller.abort();
        };
    }, [key, technology, version]);

    return preview;
}

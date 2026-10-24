/**
 * GETs JSON from one of the admin endpoints (search, runtime evaluation). Aborting the signal cancels a request that a
 * newer one replaced.
 */
export async function getJson<TResponse>(url: string, signal?: AbortSignal): Promise<TResponse> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal,
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return (await response.json()) as TResponse;
}

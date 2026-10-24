import { useEffect, useMemo, useRef } from 'react';

/** Calls the latest `callback` once the arguments stop changing for `delay` ms. */
export function useDebouncedCallback<TArgs extends unknown[]>(callback: (...args: TArgs) => void, delay: number): (...args: TArgs) => void {
    const callbackRef = useRef(callback);
    const timeoutRef = useRef<number | undefined>(undefined);

    useEffect(() => {
        callbackRef.current = callback;
    }, [callback]);

    useEffect(() => () => window.clearTimeout(timeoutRef.current), []);

    return useMemo(
        () =>
            (...args: TArgs) => {
                window.clearTimeout(timeoutRef.current);
                timeoutRef.current = window.setTimeout(() => callbackRef.current(...args), delay);
            },
        [delay],
    );
}

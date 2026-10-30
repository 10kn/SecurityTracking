import { useEffect, useMemo, useRef } from 'react';

/** Calls the latest `callback` once the arguments stop changing for `delay` ms. `cancel()` drops a call still waiting, as unmounting does. */
export function useDebouncedCallback<TArgs extends unknown[]>(callback: (...args: TArgs) => void, delay: number): ((...args: TArgs) => void) & { cancel: () => void } {
    const callbackRef = useRef(callback);
    const timeoutRef = useRef<number | undefined>(undefined);

    useEffect(() => {
        callbackRef.current = callback;
    }, [callback]);

    const debounced = useMemo(() => {
        const cancel = () => {
            window.clearTimeout(timeoutRef.current);
            timeoutRef.current = undefined;
        };

        return Object.assign(
            (...args: TArgs) => {
                cancel();
                timeoutRef.current = window.setTimeout(() => {
                    timeoutRef.current = undefined;
                    callbackRef.current(...args);
                }, delay);
            },
            { cancel },
        );
    }, [delay]);

    useEffect(() => debounced.cancel, [debounced]);

    return debounced;
}

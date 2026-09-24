import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { type TableParams } from './types';

interface UseTableQueryOptions {
    /** Defaults to the current path. */
    url?: string;
    /** Inertia partial reload: only these props are refreshed, e.g. ['products']. */
    only?: string[];
}

/** The current query string as an object. */
export function currentTableParams(): Record<string, string> {
    if (typeof window === 'undefined') {
        return {};
    }

    return Object.fromEntries(new URLSearchParams(window.location.search).entries());
}

/**
 * Merge params into the current query string and reload the page with Inertia, keeping state and scroll.
 * Empty values are removed. Filter controls in the toolbar use this too, so every filter lives in the URL.
 *
 *     const { update, loading } = useTableQuery({ only: ['products'] });
 *     update({ status: 'active', page: 1 });
 */
export function useTableQuery({ url, only }: UseTableQueryOptions = {}) {
    const [loading, setLoading] = useState(false);

    const update = useCallback(
        (params: TableParams) => {
            const merged: Record<string, string | number | boolean> = { ...currentTableParams() };

            for (const [key, value] of Object.entries(params)) {
                if (value === undefined || value === null || value === '') {
                    delete merged[key];
                } else {
                    merged[key] = value;
                }
            }

            if (merged.page === 1 || merged.page === '1') {
                delete merged.page;
            }

            router.get(url ?? (typeof window === 'undefined' ? '' : window.location.pathname), merged, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            });
        },
        [url, only],
    );

    return { update, loading };
}

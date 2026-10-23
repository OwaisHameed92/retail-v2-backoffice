import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { type StoreInfo, type StorePageProps } from './types';

/** The store frame every store page shares (always set on store pages; error pages may lack it). */
export function useStore(): StoreInfo | undefined {
    return usePage<StorePageProps>().props.store;
}

/**
 * Adds a product to the basket (POST basket, server-side basket in the session). Keeps the scroll position; the
 * shared basket summary and the flash toast update from the reply.
 */
export function useAddToBasket() {
    const store = useStore();
    const [busy, setBusy] = useState<string | null>(null);

    const add = (productId: string, quantity: string, onDone?: () => void) => {
        if (!store) {
            return;
        }

        router.post(
            store.urls.basketAdd,
            { product: productId, quantity },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setBusy(productId),
                onFinish: () => setBusy(null),
                onSuccess: () => onDone?.(),
            },
        );
    };

    return { add, busy };
}

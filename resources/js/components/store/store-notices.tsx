import { usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Info, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { type BasketSummary, type StoreInfo, type StorePageProps } from './types';

/** Error keys the basket buttons can get back (shown here; forms show their own fields inline). */
const BASKET_ERRORS = ['product', 'quantity', 'shopId'];

/**
 * Messages above the page: the store not taking orders, basket lines removed or changed since the last view, and
 * the result of the last basket change (a short toast, announced to screen readers).
 */
export function StoreNotices({ store, basket }: { store: StoreInfo; basket?: BasketSummary }) {
    const { flash, errors } = usePage<StorePageProps>().props;
    const error = BASKET_ERRORS.map((key) => errors?.[key]).find(Boolean) ?? flash?.error ?? null;

    return (
        <div className="flex flex-col gap-3 empty:hidden [&:not(:empty)]:mb-6">
            {!store.takingOrders && (
                <div className="bg-warn-soft text-ink flex items-start gap-3 rounded-xl px-4 py-3 text-sm" role="status">
                    <Info aria-hidden="true" className="text-warn mt-0.5 size-5 shrink-0" />
                    <p>
                        <strong className="font-bold">We are not taking online orders right now.</strong> You can still look around; the prices are
                        the ones on our shelves.
                    </p>
                </div>
            )}
            {basket && basket.notices.length > 0 && (
                <div className="bg-lime-soft text-ink flex items-start gap-3 rounded-xl px-4 py-3 text-sm" role="status">
                    <Info aria-hidden="true" className="mt-0.5 size-5 shrink-0" />
                    <div className="flex flex-col gap-1">
                        <strong className="font-bold">Your basket has changed</strong>
                        <ul className="flex flex-col gap-0.5">
                            {basket.notices.map((notice) => (
                                <li key={notice}>{notice}</li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}
            {error && (
                <div className="text-ink ring-sale flex items-start gap-3 rounded-xl bg-white px-4 py-3 text-sm ring-2" role="alert">
                    <CircleAlert aria-hidden="true" className="text-sale mt-0.5 size-5 shrink-0" />
                    <p className="font-semibold">{error}</p>
                </div>
            )}
            <Toast message={flash?.success ?? null} />
        </div>
    );
}

function Toast({ message }: { message: string | null }) {
    const [shown, setShown] = useState<string | null>(message);

    useEffect(() => {
        setShown(message);

        if (!message) {
            return;
        }

        const timer = window.setTimeout(() => setShown(null), 4500);

        return () => window.clearTimeout(timer);
    }, [message]);

    return (
        <div aria-live="polite" className="pointer-events-none fixed inset-x-0 bottom-24 z-50 flex justify-center px-4 md:bottom-8">
            {shown && (
                <div className="bg-brand-deep pointer-events-auto flex max-w-lg items-center gap-3 rounded-full py-2 pr-2 pl-4 text-sm font-semibold text-white shadow-2xl">
                    <CircleCheck aria-hidden="true" className="text-lime size-5 shrink-0" />
                    <span className="py-1.5">{shown}</span>
                    <button
                        type="button"
                        onClick={() => setShown(null)}
                        className="flex size-11 shrink-0 items-center justify-center rounded-full hover:bg-white/10"
                        aria-label="Close message"
                    >
                        <X aria-hidden="true" className="size-4" />
                    </button>
                </div>
            )}
        </div>
    );
}

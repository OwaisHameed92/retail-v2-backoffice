import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';
import { money } from './format';
import { type BasketSummary } from './types';

/** OS-9: the checkout's method and totals, worked out on the server (App\Http\Support\Store\CheckoutPage). */
export interface SummaryTotals {
    method: 'collection' | 'delivery';
    /** Delivery fee ("0.00" = free); null while the postcode is not checked yet. */
    deliveryFee: string | null;
    grand: string;
    /** How the customer pays, for the note under the total. */
    payNote: string;
}

/** The basket's totals card (basket and checkout): items, collection (free) or delivery, the total and how it is paid. */
export function OrderSummary({
    title,
    basket,
    totals,
    children,
    className,
}: {
    title: string;
    basket: BasketSummary;
    totals?: SummaryTotals;
    children?: ReactNode;
    className?: string;
}) {
    const delivery = totals?.method === 'delivery';
    const total = totals?.grand ?? basket.total;

    return (
        <aside className={cn('border-line flex flex-col gap-3 rounded-2xl border bg-white p-5 sm:p-6', className)} aria-label={title}>
            <h2 className="font-display text-xl font-bold tracking-[-0.01em]">{title}</h2>
            <div className="flex justify-between gap-4">
                <span>Items ({basket.count})</span>
                <span className="tabular-nums">{money(basket.total)}</span>
            </div>
            <div className="flex justify-between gap-4">
                <span>{delivery ? 'Delivery' : 'Click & collect'}</span>
                {delivery && totals?.deliveryFee === null ? (
                    <span className="text-muted">Enter your postcode</span>
                ) : delivery && totals && Number(totals.deliveryFee) > 0 ? (
                    <span className="tabular-nums">{money(totals.deliveryFee ?? '0')}</span>
                ) : (
                    <span className="text-ok font-semibold">Free</span>
                )}
            </div>
            <div className="border-line flex items-baseline justify-between gap-4 border-t pt-4">
                <span className="text-lg font-bold">{basket.estimated ? 'Estimated total' : 'Total'}</span>
                <span className="text-[28px] leading-none font-bold tracking-[-0.02em] tabular-nums">{money(total)}</span>
            </div>
            <p className="text-muted text-sm">
                {totals?.payNote ?? 'You pay at the shop when you collect.'}
                {basket.estimated && ' Items sold by weight are weighed in the shop, so the final total may differ a little.'}
            </p>
            {children}
        </aside>
    );
}

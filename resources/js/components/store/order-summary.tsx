import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';
import { money } from './format';
import { type BasketSummary } from './types';

/** The basket's totals card (basket and checkout): items, collection (free), total to pay at the shop. */
export function OrderSummary({
    title,
    basket,
    children,
    className,
}: {
    title: string;
    basket: BasketSummary;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <aside className={cn('flex flex-col gap-3.5 rounded-[28px] bg-white p-6 sm:rounded-[32px] sm:p-7', className)} aria-label={title}>
            <h2 className="font-display text-[28px] font-extrabold tracking-[-0.03em]">{title}</h2>
            <div className="flex justify-between gap-4">
                <span>Items ({basket.count})</span>
                <span className="tabular-nums">{money(basket.total)}</span>
            </div>
            <div className="flex justify-between gap-4">
                <span>Click &amp; collect</span>
                <span className="text-ok font-semibold">Free</span>
            </div>
            <div className="border-line flex items-baseline justify-between gap-4 border-t pt-4">
                <span className="text-lg font-bold">{basket.estimated ? 'Estimated total' : 'Total'}</span>
                <span className="font-display text-[44px] leading-none font-extrabold tracking-[-0.04em] tabular-nums">{money(basket.total)}</span>
            </div>
            <p className="text-muted text-sm">
                You pay at the shop when you collect.
                {basket.estimated && ' Items sold by weight are weighed there, so the final total may differ a little.'}
            </p>
            {children}
        </aside>
    );
}

import { cn } from '@/lib/utils';
import { money, quantityLabel } from './format';

export interface OrderPageLine {
    name: string;
    quantity: string;
    isWeighed: boolean;
    unitPrice: string;
    lineTotal: string;
    /** OSA-18: what the shop did while picking: "changed", "unavailable", or null as ordered. */
    outcome: 'changed' | 'unavailable' | null;
    orderedQuantity: string | null;
}

interface ItemsOrder {
    lines: OrderPageLine[];
    itemsTotal: string;
    deliveryFee: string;
    grandTotal: string;
    estimated: boolean;
    changed: boolean;
    fulfilment: 'collection' | 'delivery';
    customerNote: string | null;
    substitutionsOk: boolean;
}

/** The order page's items with what the shop changed, the delivery fee and the totals (OS-9, OSA-18). */
export function OrderItems({ order }: { order: ItemsOrder }) {
    const delivery = order.fulfilment === 'delivery';

    return (
        <section aria-labelledby="items-title" className="flex flex-col gap-4 rounded-[28px] bg-white p-6">
            <h2 id="items-title" className="font-display text-[22px] font-extrabold">
                Your items
            </h2>
            {order.changed && (
                <p className="bg-paper rounded-[18px] px-4 py-3 text-sm font-semibold">The shop changed your order while picking it.</p>
            )}
            <ul className="divide-line flex flex-col divide-y">
                {order.lines.map((line, i) => (
                    <li key={`${line.name}-${i}`} className="flex items-baseline justify-between gap-4 py-2.5">
                        <span className={cn(line.outcome === 'unavailable' && 'text-muted line-through')}>
                            <span className="font-bold">{quantityLabel(line.quantity, line.isWeighed)}</span>
                            {line.isWeighed ? ' of ' : ' × '}
                            {line.name}
                            {line.outcome === 'unavailable' && <span className="text-sale ml-2 text-xs font-bold no-underline">Not available</span>}
                            {line.outcome === 'changed' && line.orderedQuantity && (
                                <span className="text-muted block text-xs">You ordered {quantityLabel(line.orderedQuantity, line.isWeighed)}</span>
                            )}
                        </span>
                        <span className="shrink-0 font-semibold tabular-nums">{money(line.lineTotal)}</span>
                    </li>
                ))}
            </ul>
            {delivery && (
                <div className="flex justify-between gap-4">
                    <span>Delivery</span>
                    <span className="tabular-nums">{Number(order.deliveryFee) > 0 ? money(order.deliveryFee) : 'Free'}</span>
                </div>
            )}
            <div className="border-line flex items-baseline justify-between border-t pt-3">
                <span className="font-bold">{order.estimated ? 'Estimated total' : 'Total'}</span>
                <span className="font-display text-3xl font-extrabold tracking-tight">{money(order.grandTotal)}</span>
            </div>
            {order.estimated && <p className="text-muted text-sm">Items sold by weight are weighed in the shop; you pay the price on the scale.</p>}
            {(order.customerNote || order.substitutionsOk) && (
                <p className="bg-paper text-body rounded-[18px] px-4 py-3 text-sm">
                    {order.customerNote && (
                        <>
                            <span className="font-bold">Your note:</span> {order.customerNote}
                            <br />
                        </>
                    )}
                    {order.substitutionsOk ? 'Substitutions allowed.' : ''}
                </p>
            )}
        </section>
    );
}

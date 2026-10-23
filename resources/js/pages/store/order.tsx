import { money, quantityLabel } from '@/components/store/format';
import { ShopCard } from '@/components/store/home-sections';
import { StoreLayout } from '@/components/store/store-layout';
import { type ShopInfo } from '@/components/store/types';
import { useStore } from '@/components/store/use-store';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Check, CircleX } from 'lucide-react';

interface Step {
    label: string;
    hint: string;
    done: boolean;
    current: boolean;
    time: string | null;
}

interface Order {
    reference: string;
    status: 'new' | 'picking' | 'ready' | 'collected' | 'cancelled';
    statusLabel: string;
    cancelReason: string | null;
    cancelledAt: string | null;
    steps: Step[];
    collect: string;
    placedAt: string | null;
    customerName: string;
    customerEmail: string;
    customerMobile: string;
    lines: { name: string; quantity: string; isWeighed: boolean; unitPrice: string; lineTotal: string }[];
    itemsTotal: string;
    estimated: boolean;
}

/** The customer's order page (signed link): confirmation right after checkout, then where the order is. */
export default function StoreOrder({ order, shop, justPlaced }: { order: Order; shop: ShopInfo; justPlaced: boolean }) {
    const store = useStore();
    const first = order.customerName.trim().split(/\s+/)[0] ?? '';
    const cancelled = order.status === 'cancelled';
    const current = order.steps.find((step) => step.current);

    return (
        <StoreLayout title={`Order ${order.reference}`}>
            <div className="mx-auto flex max-w-[1040px] flex-col gap-4">
                <section
                    className={cn(
                        'flex flex-col gap-4 rounded-[30px] p-6 sm:rounded-[36px] sm:p-12',
                        cancelled ? 'bg-ink text-white' : 'bg-brand text-on-brand',
                    )}
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'flex size-16 items-center justify-center rounded-full sm:size-[76px]',
                            cancelled ? 'text-ink bg-white' : 'bg-lime text-ink',
                        )}
                    >
                        {cancelled ? (
                            <CircleX className="size-8 sm:size-9" strokeWidth={2.4} />
                        ) : (
                            <Check className="size-8 sm:size-[38px]" strokeWidth={2.6} />
                        )}
                    </span>
                    <h1 className="font-display text-[40px] leading-[0.92] font-extrabold tracking-[-0.045em] sm:text-[72px]">
                        {cancelled ? (
                            'This order was cancelled.'
                        ) : justPlaced ? (
                            <>
                                Thanks {first},<br />
                                your order is in.
                            </>
                        ) : (
                            order.statusLabel
                        )}
                    </h1>
                    <ul className="flex flex-wrap gap-2">
                        <li className="text-ink rounded-full bg-white px-3.5 py-2 font-bold">Order {order.reference}</li>
                        {!cancelled && <li className="bg-lime text-ink rounded-full px-3.5 py-2 font-bold">Collect {order.collect}</li>}
                        <li className="rounded-full px-3.5 py-2 font-semibold ring-1 ring-current">
                            {money(order.itemsTotal)} {cancelled ? '' : order.estimated ? 'to pay at the shop (estimated)' : 'to pay at the shop'}
                        </li>
                    </ul>
                    {cancelled ? (
                        <p className="text-[15px] opacity-90">
                            {order.cancelReason ? `Reason: ${order.cancelReason}. ` : ''}Nothing was charged. Call the shop if you have a question.
                        </p>
                    ) : (
                        <p className="text-[15px] opacity-90">
                            We email updates to {order.customerEmail}. Keep this page: its link is in the email too.
                        </p>
                    )}
                </section>

                {!cancelled && (
                    <section aria-labelledby="track-title" className="flex flex-col gap-5 rounded-[28px] bg-white p-6 sm:rounded-[32px] sm:p-8">
                        <h2 id="track-title" className="font-display text-[28px] font-extrabold tracking-[-0.03em] sm:text-[32px]">
                            Where your order is
                        </h2>
                        <ol className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {order.steps.map((step, i) => (
                                <li
                                    key={step.label}
                                    aria-current={step.current ? 'step' : undefined}
                                    className={cn(
                                        'flex flex-col gap-2.5 rounded-[22px] p-4 sm:p-5',
                                        step.current ? 'bg-ink text-white' : step.done ? 'bg-lime text-ink' : 'bg-paper text-ink',
                                    )}
                                >
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'flex size-9 items-center justify-center rounded-full font-extrabold',
                                            step.current ? 'bg-lime text-ink' : step.done ? 'bg-ink text-lime' : 'text-muted bg-white',
                                        )}
                                    >
                                        {step.done && !step.current ? <Check className="size-4" strokeWidth={3} /> : i + 1}
                                    </span>
                                    <span className="text-[17px] font-extrabold">{step.label}</span>
                                    <span className={cn('text-[13px]', step.current ? 'text-white/85' : 'text-body')}>
                                        {step.done && step.time ? step.time : step.done ? 'Done' : 'Not yet'}
                                        <span className="sr-only">{step.done ? ', done' : ''}</span>
                                    </span>
                                </li>
                            ))}
                        </ol>
                        {current && <p className="bg-paper text-body rounded-[18px] px-5 py-4 text-[15px]">{current.hint}</p>}
                    </section>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <section aria-labelledby="items-title" className="flex flex-col gap-4 rounded-[28px] bg-white p-6">
                        <h2 id="items-title" className="font-display text-[22px] font-extrabold">
                            Your items
                        </h2>
                        <ul className="divide-line flex flex-col divide-y">
                            {order.lines.map((line, i) => (
                                <li key={`${line.name}-${i}`} className="flex items-baseline justify-between gap-4 py-2.5">
                                    <span>
                                        <span className="font-bold">{quantityLabel(line.quantity, line.isWeighed)}</span>
                                        {line.isWeighed ? ' of ' : ' × '}
                                        {line.name}
                                    </span>
                                    <span className="shrink-0 font-semibold tabular-nums">{money(line.lineTotal)}</span>
                                </li>
                            ))}
                        </ul>
                        <div className="border-line flex items-baseline justify-between border-t pt-3">
                            <span className="font-bold">{order.estimated ? 'Estimated total' : 'Total'}</span>
                            <span className="font-display text-3xl font-extrabold tracking-tight">{money(order.itemsTotal)}</span>
                        </div>
                        {order.estimated && (
                            <p className="text-muted text-sm">Items sold by weight are weighed in the shop; you pay the price on the scale.</p>
                        )}
                    </section>
                    <ShopCard shop={shop}>
                        <p className="text-muted text-sm">
                            Placed {order.placedAt} by {order.customerName}, {order.customerMobile}.
                        </p>
                    </ShopCard>
                </div>

                {store && (
                    <Link
                        href={store.urls.home}
                        className="text-ink flex min-h-12 items-center justify-center self-center px-4 font-bold underline underline-offset-4"
                    >
                        Back to the shop
                    </Link>
                )}
            </div>
        </StoreLayout>
    );
}

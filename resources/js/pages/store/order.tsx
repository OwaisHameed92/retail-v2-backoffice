import { money } from '@/components/store/format';
import { ShopCard } from '@/components/store/home-sections';
import { OrderItems, type OrderPageLine } from '@/components/store/order-items';
import { StoreLayout } from '@/components/store/store-layout';
import { type ShopInfo } from '@/components/store/types';
import { useStore } from '@/components/store/use-store';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { Check, CircleX, CreditCard, Loader2 } from 'lucide-react';
import { useEffect, useRef } from 'react';

interface Step {
    label: string;
    hint: string;
    done: boolean;
    current: boolean;
    time: string | null;
}

interface Order {
    reference: string;
    status: string;
    statusLabel: string;
    fulfilment: 'collection' | 'delivery';
    cancelReason: string | null;
    cancelledAt: string | null;
    steps: Step[];
    collect: string;
    placedAt: string | null;
    customerName: string;
    customerEmail: string;
    customerMobile: string;
    deliveryAddress: string | null;
    deliveryNotes: string | null;
    customerNote: string | null;
    substitutionsOk: boolean;
    payment: { method: string; label: string; paid: boolean; pending: boolean; failed: boolean; paidAmount: string | null };
    lines: OrderPageLine[];
    itemsTotal: string;
    deliveryFee: string;
    grandTotal: string;
    estimated: boolean;
    changed: boolean;
}

const POLL_MS = 3000;
const POLL_TRIES = 40;

/** The customer's order page (signed link): the confirmation after checkout, then where the order is (OS-2, OS-9). */
export default function StoreOrder({
    order,
    shop,
    justPlaced,
    retryUrl,
}: {
    order: Order;
    shop: ShopInfo;
    justPlaced: boolean;
    retryUrl: string | null;
}) {
    const store = useStore();
    const heading = useRef<HTMLHeadingElement>(null);
    const first = order.customerName.trim().split(/\s+/)[0] ?? '';
    const delivery = order.fulfilment === 'delivery';
    const stopped = order.status === 'cancelled' || order.status === 'notCollected' || order.payment.failed;
    const waiting = order.payment.pending;
    const current = order.steps.find((step) => step.current);

    // OSA-10: the confirmation opens at its heading, and screen readers start there.
    useEffect(() => {
        if (justPlaced || waiting || order.payment.failed) {
            window.scrollTo({ top: 0 });
            heading.current?.focus();
        }
    }, [justPlaced, waiting, order.payment.failed]);

    // OS-9: while DNA's result is on its way, ask again every few seconds.
    useEffect(() => {
        if (!waiting) {
            return;
        }
        let tries = 0;
        const timer = window.setInterval(() => {
            tries++;
            if (tries > POLL_TRIES) {
                window.clearInterval(timer);

                return;
            }
            router.reload({ only: ['order', 'justPlaced', 'retryUrl', 'basket'] });
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [waiting]);

    const title = order.payment.failed ? (
        'Your payment did not go through.'
    ) : waiting ? (
        'Confirming your payment…'
    ) : order.status === 'cancelled' ? (
        'This order was cancelled.'
    ) : justPlaced ? (
        <>
            Thanks {first},<br />
            your order is in.
        </>
    ) : (
        order.statusLabel
    );
    const payText = order.payment.paid
        ? `${money(order.payment.paidAmount ?? order.grandTotal)} paid by card`
        : order.payment.method === 'payOnDelivery'
          ? `${money(order.grandTotal)} to pay on delivery${order.estimated ? ' (estimated)' : ''}`
          : order.payment.method === 'cardOnline'
            ? `${money(order.grandTotal)} by card`
            : `${money(order.grandTotal)} to pay at the shop${order.estimated ? ' (estimated)' : ''}`;

    return (
        <StoreLayout title={`Order ${order.reference}`}>
            <div className="mx-auto flex max-w-[1040px] flex-col gap-4">
                <section
                    className={cn(
                        'flex flex-col gap-4 rounded-[30px] p-6 sm:rounded-[36px] sm:p-12',
                        stopped ? 'bg-ink text-white' : 'bg-brand text-on-brand',
                    )}
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'flex size-16 items-center justify-center rounded-full sm:size-[76px]',
                            stopped ? 'text-ink bg-white' : 'bg-lime text-ink',
                        )}
                    >
                        {waiting ? (
                            <Loader2 className="size-8 animate-spin sm:size-9" />
                        ) : stopped ? (
                            <CircleX className="size-8 sm:size-9" strokeWidth={2.4} />
                        ) : (
                            <Check className="size-8 sm:size-[38px]" strokeWidth={2.6} />
                        )}
                    </span>
                    <h1
                        ref={heading}
                        tabIndex={-1}
                        className="font-display text-[40px] leading-[0.92] font-extrabold tracking-[-0.045em] outline-none sm:text-[72px]"
                    >
                        {title}
                    </h1>
                    <ul className="flex flex-wrap gap-2">
                        <li className="text-ink rounded-full bg-white px-3.5 py-2 font-bold">Order {order.reference}</li>
                        {!stopped && (
                            <li className="bg-lime text-ink rounded-full px-3.5 py-2 font-bold">
                                {delivery ? 'Delivery' : 'Collect'} {order.collect}
                            </li>
                        )}
                        {!stopped && <li className="rounded-full px-3.5 py-2 font-semibold ring-1 ring-current">{payText}</li>}
                    </ul>
                    <p className="text-[15px] opacity-90" role={waiting || order.payment.failed ? 'status' : undefined}>
                        {order.payment.failed
                            ? 'No money was taken and the time you picked is free again. Your basket is still here: try again or choose another way to pay.'
                            : waiting
                              ? 'Your bank is confirming the payment. This page updates by itself; please do not pay again.'
                              : order.status === 'cancelled'
                                ? `${order.cancelReason ? `Reason: ${order.cancelReason}. ` : ''}${order.payment.paid ? 'The shop will refund your card payment.' : 'Nothing was charged.'} Call the shop if you have a question.`
                                : `We email updates to ${order.customerEmail}. Keep this page: its link is in the email too.`}
                    </p>
                    {order.payment.failed && retryUrl && (
                        <Link
                            href={retryUrl}
                            className="text-ink inline-flex min-h-12 items-center gap-2 self-start rounded-full bg-white px-6 font-bold"
                        >
                            <CreditCard aria-hidden="true" className="size-4" />
                            Back to checkout
                        </Link>
                    )}
                </section>

                {!stopped && !waiting && (
                    <section aria-labelledby="track-title" className="flex flex-col gap-5 rounded-2xl bg-white p-6 sm:p-8">
                        <h2 id="track-title" className="font-display text-[28px] font-extrabold tracking-[-0.03em] sm:text-[32px]">
                            Where your order is
                        </h2>
                        <ol className={cn('grid gap-3 sm:grid-cols-2', order.steps.length > 4 ? 'lg:grid-cols-5' : 'lg:grid-cols-4')}>
                            {order.steps.map((step, i) => (
                                <li
                                    key={step.label}
                                    aria-current={step.current ? 'step' : undefined}
                                    className={cn(
                                        'flex flex-col gap-2.5 rounded-2xl p-4 sm:p-5',
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
                        {current && <p className="bg-paper text-body rounded-xl px-5 py-4 text-[15px]">{current.hint}</p>}
                    </section>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <OrderItems order={order} />
                    <ShopCard shop={shop}>
                        {delivery && order.deliveryAddress && (
                            <p className="text-sm">
                                <span className="font-bold">Delivering to:</span> {order.deliveryAddress}
                                {order.deliveryNotes ? ` (${order.deliveryNotes})` : ''}
                            </p>
                        )}
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

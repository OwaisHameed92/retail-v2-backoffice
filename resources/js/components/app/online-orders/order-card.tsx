import { StatusPill } from '@/components/shared/status-badge';
import { formatMoney, formatTime } from '@/lib/country';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Clock, CreditCard, MessageSquare, Scale, Sparkles, Store, Truck } from 'lucide-react';
import { dayLabel, isDone, itemsLabel, OrderStatusBadge, slotTime, statusLabel } from './format';
import { OrderActions } from './order-actions';
import { type OrderCard as Card } from './types';

/**
 * One order on the board: number, customer, items, total, collection time or delivery window (OS-9: with the
 * postcode), how it is paid ("Paid by card"), with its one main action. `highlight` rings a card that arrived since
 * the last refresh.
 */
export function OrderCard({ order, showShop, highlight }: { order: Card; showShop: boolean; highlight: boolean }) {
    const done = isDone(order.status);
    const delivery = order.fulfilment === 'delivery';

    return (
        <article
            aria-label={`Order ${order.reference}`}
            className={cn(
                'bg-card shadow-card rounded-card relative flex flex-col gap-2.5 border p-3.5 transition-shadow',
                highlight && 'ring-primary bg-primary-soft ring-2',
                order.late && !done && 'border-warning',
                done && 'opacity-90',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <Link
                    href={route('app.online-orders.show', order.id)}
                    className="hover:text-primary focus-visible:ring-ring rounded-sm font-semibold tabular-nums after:absolute after:inset-0 after:content-[''] hover:underline focus-visible:ring-2 focus-visible:outline-none"
                >
                    {order.reference}
                </Link>
                <div className="flex flex-wrap justify-end gap-1">
                    {(order.fresh || highlight) && (
                        <StatusPill tone="info" className="gap-1">
                            <Sparkles className="size-3" aria-hidden />
                            Just in
                        </StatusPill>
                    )}
                    {delivery && (
                        <StatusPill tone="info" className="gap-1">
                            <Truck className="size-3" aria-hidden />
                            Delivery
                        </StatusPill>
                    )}
                    {order.status === 'outForDelivery' && <StatusPill tone="info">Out for delivery</StatusPill>}
                    {order.late && !done && (
                        <StatusPill tone="warning">
                            {order.status === 'ready' ? (delivery ? 'Waiting to go out' : 'Not collected yet') : 'Late'}
                        </StatusPill>
                    )}
                    {order.dueSoon && <StatusPill tone="warning">Due soon</StatusPill>}
                    {done && <OrderStatusBadge status={order.status} fulfilment={order.fulfilment} />}
                    {order.payment.refundNeeded && <StatusPill tone="danger">Refund in DNA portal</StatusPill>}
                </div>
            </div>

            <p className="text-sm">
                <span className="font-medium">{order.customer}</span>
                <span className="text-muted-foreground"> · {itemsLabel(order.itemCount)} · </span>
                <span className="font-semibold tabular-nums">{formatMoney(order.total)}</span>
            </p>
            {order.preview && <p className="text-muted-foreground line-clamp-1 text-xs">{order.preview}</p>}

            <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <span className="inline-flex items-center gap-1 tabular-nums">
                    <Clock className="size-3.5" aria-hidden />
                    {done && order.doneAt
                        ? `${statusLabel(order.status, order.fulfilment)} ${formatTime(order.doneAt)}`
                        : `${order.late || order.otherDay ? `${dayLabel(order.collectFrom)} ` : ''}${delivery ? 'Deliver' : 'Collect'} ${slotTime(order)}`}
                </span>
                {delivery && order.deliveryPostcode && <span className="font-medium tabular-nums">{order.deliveryPostcode}</span>}
                {showShop && (
                    <span className="inline-flex items-center gap-1">
                        <Store className="size-3.5" aria-hidden />
                        {order.shop}
                    </span>
                )}
                {order.hasWeighed && (
                    <span className="inline-flex items-center gap-1">
                        <Scale className="size-3.5" aria-hidden />
                        Weighed items
                    </span>
                )}
                {order.hasNote && (
                    <span className="inline-flex items-center gap-1">
                        <MessageSquare className="size-3.5" aria-hidden />
                        Note
                    </span>
                )}
                {order.payment.paid ? (
                    <span className="text-success-foreground inline-flex items-center gap-1 font-medium">
                        <CreditCard className="size-3.5" aria-hidden />
                        Paid by card
                    </span>
                ) : (
                    !done && (
                        <span className="text-foreground font-medium">
                            {order.payment.method === 'payOnDelivery' ? 'Pay on delivery' : 'Pay at shop'}
                        </span>
                    )
                )}
            </div>

            {order.status === 'cancelled' && order.cancelReason && (
                <p className="text-muted-foreground line-clamp-2 text-xs">“{order.cancelReason}”</p>
            )}

            {!done && <OrderActions order={order} className="relative z-10 pt-1" stretch />}
        </article>
    );
}

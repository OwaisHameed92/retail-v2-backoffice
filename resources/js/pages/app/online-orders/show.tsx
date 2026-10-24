import { OrderStatusBadge, slotDayTime, statusLabel } from '@/components/app/online-orders/format';
import { LineChange } from '@/components/app/online-orders/line-change';
import { OrderActions } from '@/components/app/online-orders/order-actions';
import { OrderFacts } from '@/components/app/online-orders/order-facts';
import { OrderLines } from '@/components/app/online-orders/order-lines';
import { type OnlineOrderShowProps, type OrderStatus } from '@/components/app/online-orders/types';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { Timeline, type TimelineItem, type TimelineTone } from '@/components/shared/timeline';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMoney, formatTime } from '@/lib/country';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCheck, CircleSlash, CreditCard, Inbox, PackageCheck, Printer, ShoppingBasket, Truck, Undo2, X, type LucideIcon } from 'lucide-react';

const HISTORY: Record<OrderStatus, { title: string; icon: LucideIcon; tone: TimelineTone }> = {
    new: { title: 'Order placed online', icon: Inbox, tone: 'primary' },
    picking: { title: 'Picking started', icon: ShoppingBasket, tone: 'neutral' },
    ready: { title: 'Ready', icon: PackageCheck, tone: 'success' },
    outForDelivery: { title: 'Out for delivery', icon: Truck, tone: 'neutral' },
    collected: { title: 'Collected', icon: CheckCheck, tone: 'success' },
    delivered: { title: 'Delivered', icon: CheckCheck, tone: 'success' },
    notCollected: { title: 'Not collected', icon: CircleSlash, tone: 'danger' },
    cancelled: { title: 'Cancelled', icon: X, tone: 'danger' },
};

/** One online order (OS-4, OS-9): what to pick, who gets it, where and when, how it is paid, its stages, and the next move. */
export default function OnlineOrderShow({ order }: OnlineOrderShowProps) {
    const delivery = order.fulfilment === 'delivery';
    const history: TimelineItem[] = [...order.history].reverse().map((step) => ({
        id: step.status,
        icon: HISTORY[step.status].icon,
        tone: HISTORY[step.status].tone,
        title: step.status === 'ready' || step.status === 'notCollected' ? statusLabel(step.status, order.fulfilment) : HISTORY[step.status].title,
        time: formatDateTime(step.at),
        body: step.note ? `“${step.note}”` : undefined,
    }));
    const extra = delivery ? [{ label: 'Delivery', value: Number(order.deliveryFee) > 0 ? formatMoney(order.deliveryFee) : 'Free' }] : undefined;

    return (
        <AppLayout>
            <Head title={`Online order ${order.reference}`} />
            <PageHeader
                title={`Order ${order.reference}`}
                status={<OrderStatusBadge status={order.status} fulfilment={order.fulfilment} />}
                back={{ href: route('app.online-orders.index'), label: 'Online orders' }}
                description={`${order.customerName} · ${delivery ? `deliver ${slotDayTime(order)} to ${order.deliveryPostcode ?? ''}` : `collect ${slotDayTime(order)} at ${order.shop}`} · ${order.payment.label.toLowerCase()}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {order.undo && (
                            <Button
                                variant="outline"
                                onClick={() => router.post(route('app.online-orders.undo', order.id), {}, { preserveScroll: true })}
                            >
                                <Undo2 className="size-4" aria-hidden />
                                Undo ({statusLabel(order.undo.to, order.fulfilment)})
                                {order.undo.until ? ` until ${formatTime(order.undo.until)}` : ''}
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <Link href={route('app.online-orders.pick-list', order.id)}>
                                <Printer className="size-4" aria-hidden />
                                Pick list
                            </Link>
                        </Button>
                        <OrderActions order={order} size="default" />
                    </div>
                }
            />

            {order.refundDue && (
                <Alert variant="warning" className="mb-6">
                    <CreditCard />
                    <AlertDescription>
                        Refund needed in your DNA portal: {formatMoney(order.refundDue)} of the {formatMoney(order.paidAmount ?? '0')} paid by card
                        {order.paymentReference ? ` (DNA transaction ${order.paymentReference})` : ''}. There is no automatic refund yet.
                    </AlertDescription>
                </Alert>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="grid content-start gap-6 lg:col-span-2">
                    <SectionCard
                        title="Items"
                        description={
                            order.canChangeLines
                                ? 'Mark an item not available or change its quantity while you pick; the total follows.'
                                : order.hasWeighed
                                  ? 'Weighed items are priced on the scale when the till rings the order up.'
                                  : 'Shelf prices when ordered. The till applies any offers.'
                        }
                        flush
                    >
                        <OrderLines
                            lines={order.lines}
                            total={order.total}
                            extra={extra}
                            action={order.canChangeLines ? (line) => <LineChange orderId={order.id} line={line} /> : undefined}
                        />
                    </SectionCard>
                    {(order.customerNote || order.substitutionsOk) && (
                        <SectionCard title="From the customer">
                            {order.customerNote && <p className="text-sm whitespace-pre-line">“{order.customerNote}”</p>}
                            <p className="text-muted-foreground mt-2 text-sm">
                                {order.substitutionsOk ? 'Substitutions allowed: a similar item at the same or a lower price.' : 'No substitutions.'}
                            </p>
                        </SectionCard>
                    )}
                    {order.status === 'cancelled' && order.cancelReason && (
                        <SectionCard title="Why it was cancelled">
                            <p className="text-sm">“{order.cancelReason}”</p>
                        </SectionCard>
                    )}
                </div>

                <div className="grid content-start gap-6">
                    <OrderFacts order={order} />
                    <SectionCard title="History" description={`Now: ${statusLabel(order.status, order.fulfilment)}`}>
                        <Timeline items={history} />
                    </SectionCard>
                </div>
            </div>
        </AppLayout>
    );
}

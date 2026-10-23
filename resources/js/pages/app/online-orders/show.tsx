import { OrderStatusBadge, slotDayTime, STATUS_LABELS } from '@/components/app/online-orders/format';
import { OrderActions } from '@/components/app/online-orders/order-actions';
import { OrderLines } from '@/components/app/online-orders/order-lines';
import { type OnlineOrderShowProps, type OrderStatus } from '@/components/app/online-orders/types';
import { DescriptionList } from '@/components/shared/description-list';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Timeline, type TimelineItem, type TimelineTone } from '@/components/shared/timeline';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/country';
import { Head, Link } from '@inertiajs/react';
import { CheckCheck, Inbox, PackageCheck, Printer, ShoppingBasket, X, type LucideIcon } from 'lucide-react';

const HISTORY: Record<OrderStatus, { title: string; icon: LucideIcon; tone: TimelineTone }> = {
    new: { title: 'Order placed online', icon: Inbox, tone: 'primary' },
    picking: { title: 'Picking started', icon: ShoppingBasket, tone: 'neutral' },
    ready: { title: 'Ready to collect: customer emailed', icon: PackageCheck, tone: 'success' },
    collected: { title: 'Collected', icon: CheckCheck, tone: 'success' },
    cancelled: { title: 'Cancelled: customer emailed', icon: X, tone: 'danger' },
};

/** One online order (OS-4): what to pick, who collects it and when, its stages, and the next move. */
export default function OnlineOrderShow({ order }: OnlineOrderShowProps) {
    const history: TimelineItem[] = [...order.history].reverse().map((step) => ({
        id: step.status,
        icon: HISTORY[step.status].icon,
        tone: HISTORY[step.status].tone,
        title: HISTORY[step.status].title,
        time: formatDateTime(step.at),
        body: step.note ? `“${step.note}”` : undefined,
    }));

    return (
        <AppLayout>
            <Head title={`Online order ${order.reference}`} />
            <PageHeader
                title={`Order ${order.reference}`}
                status={<OrderStatusBadge status={order.status} />}
                back={{ href: route('app.online-orders.index'), label: 'Online orders' }}
                description={`${order.customerName} · collect ${slotDayTime(order)} at ${order.shop} · pay at the shop`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
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

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="grid content-start gap-6 lg:col-span-2">
                    <SectionCard
                        title="Items"
                        description={
                            order.hasWeighed
                                ? 'Weighed items are priced on the scale when the till rings the order up.'
                                : 'Shelf prices when ordered. The till applies any offers.'
                        }
                        flush
                    >
                        <OrderLines lines={order.lines} total={order.total} />
                    </SectionCard>
                    {order.status === 'cancelled' && order.cancelReason && (
                        <SectionCard title="Why it was cancelled">
                            <p className="text-sm">“{order.cancelReason}”</p>
                        </SectionCard>
                    )}
                </div>

                <div className="grid content-start gap-6">
                    <SectionCard title="Customer">
                        <DescriptionList
                            layout="rows"
                            items={[
                                { label: 'Name', value: order.customerName },
                                {
                                    label: 'Mobile',
                                    value: (
                                        <a className="text-primary hover:underline" href={`tel:${order.customerMobile.replace(/\s+/g, '')}`}>
                                            {order.customerMobile}
                                        </a>
                                    ),
                                },
                                {
                                    label: 'Email',
                                    value: (
                                        <a className="text-primary break-all hover:underline" href={`mailto:${order.customerEmail}`}>
                                            {order.customerEmail}
                                        </a>
                                    ),
                                },
                            ]}
                        />
                    </SectionCard>
                    <SectionCard title="Collection">
                        <DescriptionList
                            layout="rows"
                            items={[
                                { label: 'Shop', value: order.shop },
                                { label: 'Address', value: order.shopAddress },
                                { label: 'Time', value: slotDayTime(order) },
                                { label: 'Payment', value: 'Pay at the shop' },
                            ]}
                        />
                        {order.late && (
                            <StatusPill tone="warning" className="mt-3">
                                {order.status === 'ready' ? 'Not collected yet: the slot has passed' : 'Late: the slot has passed'}
                            </StatusPill>
                        )}
                    </SectionCard>
                    <SectionCard title="History" description={`Now: ${STATUS_LABELS[order.status]}`}>
                        <Timeline items={history} />
                    </SectionCard>
                </div>
            </div>
        </AppLayout>
    );
}

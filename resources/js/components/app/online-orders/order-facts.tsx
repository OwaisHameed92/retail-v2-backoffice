import { DescriptionList } from '@/components/shared/description-list';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatMoney } from '@/lib/country';
import { router } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { slotDayTime } from './format';
import { type OrderDetail } from './types';

/** The order page's side cards: the customer (and the order link, OSA-22), the collection or delivery, and the payment. */
export function OrderFacts({ order }: { order: OrderDetail }) {
    const delivery = order.fulfilment === 'delivery';

    return (
        <>
            <SectionCard
                title="Customer"
                actions={
                    !order.anonymised && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.post(route('app.online-orders.resend', order.id), {}, { preserveScroll: true })}
                        >
                            <Send className="size-4" aria-hidden />
                            Send order link
                        </Button>
                    )
                }
            >
                {order.anonymised ? (
                    <p className="text-muted-foreground text-sm">The customer&apos;s details were removed after the retention period.</p>
                ) : (
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
                            { label: 'Order link', value: order.linkExpiresAt ? `Works until ${formatDateTime(order.linkExpiresAt)}` : null },
                        ]}
                    />
                )}
            </SectionCard>
            <SectionCard title={delivery ? 'Delivery' : 'Collection'}>
                <DescriptionList
                    layout="rows"
                    items={[
                        { label: delivery ? 'From' : 'Shop', value: order.shop },
                        delivery ? { label: 'Deliver to', value: order.deliveryAddress } : { label: 'Address', value: order.shopAddress },
                        ...(delivery && order.deliveryNotes ? [{ label: 'Driver notes', value: order.deliveryNotes }] : []),
                        { label: 'Time', value: slotDayTime(order) },
                    ]}
                />
                {order.late && (
                    <StatusPill tone="warning" className="mt-3">
                        {order.status === 'ready' && !delivery ? 'Not collected yet: the slot has passed' : 'Late: the slot has passed'}
                    </StatusPill>
                )}
            </SectionCard>
            <SectionCard title="Payment">
                <DescriptionList
                    layout="rows"
                    items={[
                        { label: 'How', value: order.payment.paid ? 'Paid by card online (DNA Payments)' : order.payment.label },
                        ...(order.payment.paid ? [{ label: 'Paid', value: formatMoney(order.paidAmount ?? order.total) }] : []),
                        ...(order.paymentReference ? [{ label: 'DNA transaction', value: order.paymentReference }] : []),
                        { label: 'Total now', value: formatMoney(order.total) },
                    ]}
                />
                <p className="text-muted-foreground mt-3 text-xs">
                    {order.payment.paid
                        ? 'Ring it up on the till as paid: do not take the money again.'
                        : delivery
                          ? 'The driver takes the payment; ring the order up on the till before it goes out.'
                          : 'The till takes the payment when the customer collects.'}
                </p>
            </SectionCard>
        </>
    );
}

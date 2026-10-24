import AppLogoIcon from '@/components/app-logo-icon';
import { slotDayTime } from '@/components/app/online-orders/format';
import { OrderLines } from '@/components/app/online-orders/order-lines';
import { type OnlineOrderShowProps } from '@/components/app/online-orders/types';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatMoney, formatNumber } from '@/lib/country';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';

const PAGE_CSS = '@page { size: A4 portrait; margin: 12mm; } @media print { html, body { background: white !important; } }';

/**
 * An online order's pick list, laid out for paper (OS-4): order number, customer, collection slot and shop, and
 * every line with a tick box. The toolbar is hidden when printing.
 */
export default function OnlineOrderPickList({ business, order }: OnlineOrderShowProps) {
    const delivery = order.fulfilment === 'delivery';
    return (
        <div className="bg-background text-foreground min-h-screen print:bg-white print:text-black">
            <Head title={`Pick list ${order.reference}`} />
            <style>{PAGE_CSS}</style>

            <div className="bg-card sticky top-0 z-10 flex items-center justify-between gap-3 border-b px-4 py-3 print:hidden">
                <Button variant="ghost" asChild>
                    <Link href={route('app.online-orders.show', order.id)}>
                        <ArrowLeft className="size-4" aria-hidden />
                        Back to the order
                    </Link>
                </Button>
                <Button onClick={() => window.print()}>
                    <Printer className="size-4" aria-hidden />
                    Print
                </Button>
            </div>

            <main className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6 text-sm print:max-w-none print:gap-4 print:p-0">
                <header className="flex items-start justify-between gap-6 border-b pb-4">
                    <div>
                        <p className="text-muted-foreground print:text-neutral-600">{business} · online order to pick</p>
                        <h1 className="text-3xl font-bold tracking-tight tabular-nums">{order.reference}</h1>
                        <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                            <dt className="text-muted-foreground print:text-neutral-600">Customer</dt>
                            <dd className="font-medium">
                                {order.customerName} · {order.customerMobile}
                            </dd>
                            <dt className="text-muted-foreground print:text-neutral-600">{delivery ? 'Deliver' : 'Collect'}</dt>
                            <dd className="font-medium">
                                {slotDayTime(order)} {delivery ? `from ${order.shop}` : `at ${order.shop}`}
                            </dd>
                            {delivery && (
                                <>
                                    <dt className="text-muted-foreground print:text-neutral-600">Address</dt>
                                    <dd className="font-medium">
                                        {order.deliveryAddress}
                                        {order.deliveryNotes ? ` (${order.deliveryNotes})` : ''}
                                    </dd>
                                </>
                            )}
                            <dt className="text-muted-foreground print:text-neutral-600">Items</dt>
                            <dd className="font-medium">
                                {formatNumber(order.itemCount)} to pick ·{' '}
                                {order.payment.paid ? 'PAID BY CARD: do not take payment' : order.payment.label.toLowerCase()}
                            </dd>
                            {order.customerNote && (
                                <>
                                    <dt className="text-muted-foreground print:text-neutral-600">Note</dt>
                                    <dd className="font-medium">“{order.customerNote}”</dd>
                                </>
                            )}
                            <dt className="text-muted-foreground print:text-neutral-600">Substitutions</dt>
                            <dd>{order.substitutionsOk ? 'Allowed (same or lower price)' : 'No'}</dd>
                            <dt className="text-muted-foreground print:text-neutral-600">Placed</dt>
                            <dd>{formatDateTime(order.placedAt)}</dd>
                        </dl>
                    </div>
                    <AppLogoIcon className="size-10 shrink-0" />
                </header>

                <OrderLines
                    lines={order.lines}
                    total={order.total}
                    pickList
                    extra={
                        delivery ? [{ label: 'Delivery', value: Number(order.deliveryFee) > 0 ? formatMoney(order.deliveryFee) : 'Free' }] : undefined
                    }
                />

                <p className="text-muted-foreground print:text-neutral-600">
                    {order.hasWeighed && 'Weigh the weighed items on the till when the customer collects. '}
                    {order.payment.paid
                        ? 'Ring the order up on the till as already paid online: the till takes the stock off; do not take the money again.'
                        : 'Ring the whole order up on the till as a normal sale: the till takes the payment and the stock off.'}
                </p>
            </main>
        </div>
    );
}

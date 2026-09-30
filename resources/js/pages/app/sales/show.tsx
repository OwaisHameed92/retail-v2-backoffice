import { Amount, formatDateTime, SaleKindPill } from '@/components/app/sales/format';
import { ReceiptLines } from '@/components/app/sales/receipt-lines';
import { ActivityCard, DetailsCard, LinkedCard, PaymentsCard } from '@/components/app/sales/receipt-side';
import { type SaleShowProps } from '@/components/app/sales/types';
import { PageHeader } from '@/components/shared/page-header';
import { StatusPill } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Info } from 'lucide-react';

/** One receipt (module 4.6): read only, every figure exactly as the till stored it. */
export default function SaleShow(props: SaleShowProps) {
    const { sale, totals, linked } = props;
    const voided = sale.status === 'voided';
    const refunded = linked.some((s) => s.type === 'refund' && s.status === 'completed');
    const where = [sale.shop, sale.till, sale.staff].filter(Boolean).join(' · ');

    return (
        <AppLayout>
            <Head title={`Receipt ${sale.receiptNumber}`} />

            <PageHeader
                title={sale.receiptNumber}
                back={{ href: route('app.sales.index'), label: 'Sales' }}
                status={
                    <span className="flex items-center gap-1.5">
                        <SaleKindPill type={sale.type} status={sale.status} />
                        {refunded && <StatusPill tone="warning">Refunded</StatusPill>}
                    </span>
                }
                description={`${where}${where ? ' · ' : ''}${formatDateTime(sale.voidedAt ?? sale.completedAt)}`}
                actions={
                    <div className="text-right">
                        <p className="text-muted-foreground text-xs">{voided ? 'Basket value' : 'Total'}</p>
                        <Amount value={totals.total} voided={voided} className="text-2xl font-semibold" />
                    </div>
                }
            />

            {voided && (
                <Alert variant="warning">
                    <Info />
                    <AlertDescription>
                        This basket was voided at the till, so no money was taken and it is not counted in sales.
                        {sale.voidReason && ` Reason: ${sale.voidReason}.`}
                    </AlertDescription>
                </Alert>
            )}
            {sale.type === 'deposit' && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>
                        An order deposit is money held for a customer order. It is not a sale; the goods count as sold when the order is collected.
                    </AlertDescription>
                </Alert>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
                <div className="grid gap-4 lg:col-span-2">
                    <ReceiptLines lines={props.lines} totals={totals} vat={props.vat} canViewProducts={props.canViewProducts} voided={voided} />
                    <ActivityCard events={props.events} />
                </div>
                <div className="grid gap-4">
                    <PaymentsCard payments={props.payments} totals={totals} canViewCustomers={props.canViewCustomers} />
                    <LinkedCard original={props.original} linked={linked} />
                    <DetailsCard sale={sale} canViewCustomers={props.canViewCustomers} />
                </div>
            </div>
        </AppLayout>
    );
}

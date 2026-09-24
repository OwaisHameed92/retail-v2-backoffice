import { BillingActivity } from '@/components/admin/billing/billing-activity';
import { formatDateTimeShort } from '@/components/admin/billing/format';
import { InvoiceStatusBadge } from '@/components/admin/billing/invoice-status-badge';
import { type ActivityRow, type PaymentDetail } from '@/components/admin/billing/types';
import { DescriptionList } from '@/components/shared/description-list';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { Badge } from '@/components/ui/badge';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link } from '@inertiajs/react';
import { Banknote } from 'lucide-react';

interface PaymentShowProps {
    payment: PaymentDetail;
    activity: ActivityRow[];
}

export default function PaymentShow({ payment, activity }: PaymentShowProps) {
    return (
        <AdminLayout>
            <Head title={`Payment ${payment.number}`} />

            <PageHeader
                title={payment.amount}
                status={<Badge variant="success">{payment.methodLabel}</Badge>}
                breadcrumbs={[
                    { title: 'Billing', href: route('admin.billing.index') },
                    { title: 'Payments', href: route('admin.billing.payments.index') },
                    { title: payment.number },
                ]}
                media={<InitialsAvatar name={payment.company.name} shape="square" size="lg" icon={Banknote} />}
                description={
                    <>
                        Received from{' '}
                        <Link href={route('admin.tenants.show', { company: payment.company.id, tab: 'billing' })} className="text-foreground font-medium hover:underline">
                            {payment.company.name}
                        </Link>{' '}
                        on {formatDateTimeShort(payment.receivedAt)}.
                    </>
                }
            />

            <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="grid gap-6">
                    <SectionCard
                        title="Paid towards"
                        description={payment.hasCredit ? `${payment.unallocated} is not on any invoice yet: it is kept as credit and used on the next invoice.` : undefined}
                        flush={payment.allocations.length > 0}
                    >
                        {payment.allocations.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Not put on any invoice. The whole amount is the customer’s credit.</p>
                        ) : (
                            <ul className="divide-y">
                                {payment.allocations.map((allocation) => (
                                    <li key={allocation.id}>
                                        <Link
                                            href={route('admin.billing.invoices.show', allocation.invoiceId)}
                                            className="hover:bg-muted/50 flex items-center justify-between gap-3 px-5 py-3 text-sm sm:px-6"
                                        >
                                            <span className="flex min-w-0 items-center gap-2">
                                                <span className="font-mono text-[13px] font-medium">{allocation.invoiceNumber}</span>
                                                {allocation.invoiceStatus && <InvoiceStatusBadge status={allocation.invoiceStatus} />}
                                                {allocation.released && <Badge variant="neutral">Invoice voided: moved to credit</Badge>}
                                            </span>
                                            <span className={allocation.released ? 'text-muted-foreground line-through tabular-nums' : 'font-semibold tabular-nums'}>{allocation.amount}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>

                    <SectionCard title="Activity">
                        <BillingActivity rows={activity} />
                    </SectionCard>
                </div>

                <SectionCard title="Details">
                    <DescriptionList
                        layout="rows"
                        items={[
                            { label: 'Receipt', value: payment.number, mono: true },
                            { label: 'Amount', value: payment.amount },
                            { label: 'Method', value: payment.methodLabel },
                            { label: 'Received', value: formatDateTimeShort(payment.receivedAt) },
                            { label: 'Reference', value: payment.reference },
                            { label: 'Recorded by', value: payment.receivedBy },
                            { label: 'Credit left', value: payment.hasCredit ? payment.unallocated : 'None' },
                            ...(payment.gateway ? [{ label: 'Gateway', value: `${payment.gateway} ${payment.gatewayReference ?? ''}`.trim(), mono: true }] : []),
                            { label: 'Notes', value: payment.notes },
                        ]}
                    />
                </SectionCard>
            </div>
        </AdminLayout>
    );
}

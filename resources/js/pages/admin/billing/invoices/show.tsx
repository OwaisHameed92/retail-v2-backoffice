import { BillingActivity } from '@/components/admin/billing/billing-activity';
import { dueLabel, formatDateTimeShort, formatDay, invoiceStatusHelp } from '@/components/admin/billing/format';
import { InvoiceActions } from '@/components/admin/billing/invoice-actions';
import { InvoiceDocument } from '@/components/admin/billing/invoice-document';
import { InvoiceStatusBadge } from '@/components/admin/billing/invoice-status-badge';
import { type ActivityRow, type InvoiceDetail } from '@/components/admin/billing/types';
import { DescriptionList } from '@/components/shared/description-list';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { MoneyIcon } from '@/components/shared/money-icon';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import AdminLayout from '@/layouts/admin-layout';
import { formatMoney, taxName } from '@/lib/country';
import { Head, Link } from '@inertiajs/react';
import { AlarmClock, Ban, Banknote, CalendarClock, FilePen, Receipt, Scale } from 'lucide-react';

interface InvoiceShowProps {
    invoice: InvoiceDetail;
    activity: ActivityRow[];
}

function StatusAlert({ invoice }: { invoice: InvoiceDetail }) {
    if (invoice.status === 'overdue') {
        return (
            <Alert variant="destructive" className="bg-danger-soft">
                <AlarmClock className="size-4" />
                <AlertTitle>{dueLabel(invoice.dueDate, true) ?? 'Overdue'}</AlertTitle>
                <AlertDescription>
                    {invoice.balance} is still owed.{' '}
                    {invoice.companyStatus === 'suspended'
                        ? 'The account is suspended until it is paid.'
                        : 'The account is suspended if it stays unpaid.'}
                </AlertDescription>
            </Alert>
        );
    }
    if (invoice.status === 'void') {
        return (
            <Alert>
                <Ban className="size-4" />
                <AlertTitle>Void since {formatDateTimeShort(invoice.voidedAt)}</AlertTitle>
                <AlertDescription>
                    {invoice.voidReason ?? 'No reason given.'}
                    {invoice.replacedBy && (
                        <>
                            {' '}
                            Replaced by{' '}
                            <Link
                                href={route('admin.billing.invoices.show', invoice.replacedBy.id)}
                                className="text-primary font-medium hover:underline"
                            >
                                {invoice.replacedBy.number}
                            </Link>
                            .
                        </>
                    )}
                </AlertDescription>
            </Alert>
        );
    }
    if (invoice.status === 'draft') {
        return (
            <Alert variant="info">
                <FilePen className="size-4" />
                <AlertTitle>Draft</AlertTitle>
                <AlertDescription>
                    {invoiceStatusHelp.draft}
                    {invoice.replaces && (
                        <>
                            {' '}
                            It replaces{' '}
                            <Link
                                href={route('admin.billing.invoices.show', invoice.replaces.id)}
                                className="text-primary font-medium hover:underline"
                            >
                                {invoice.replaces.number}
                            </Link>
                            , which is void.
                        </>
                    )}
                </AlertDescription>
            </Alert>
        );
    }

    return null;
}

export default function InvoiceShow({ invoice, activity }: InvoiceShowProps) {
    const title = invoice.number ?? 'Draft invoice';
    const due = dueLabel(invoice.dueDate, invoice.isOpen);

    return (
        <AdminLayout>
            <Head title={title} />

            <PageHeader
                title={title}
                status={<InvoiceStatusBadge status={invoice.status} withHelp />}
                breadcrumbs={[
                    { title: 'Billing', href: route('admin.billing.index') },
                    { title: 'Invoices', href: route('admin.billing.invoices.index') },
                    { title },
                ]}
                media={<InitialsAvatar name={invoice.company.name} shape="square" size="lg" icon={Receipt} />}
                description={
                    <>
                        <Link
                            href={route('admin.tenants.show', { company: invoice.company.id, tab: 'billing' })}
                            className="text-foreground font-medium hover:underline"
                        >
                            {invoice.company.name}
                        </Link>{' '}
                        · {invoice.period}
                    </>
                }
                meta={
                    <>
                        {invoice.issueDate && <span>Issued {formatDay(invoice.issueDate)}</span>}
                        {invoice.dueDate && <span>Due {formatDay(invoice.dueDate)}</span>}
                        {invoice.sentCount > 0 && <span>Emailed {invoice.sentCount === 1 ? 'once' : `${invoice.sentCount} times`}</span>}
                        {invoice.autoGenerated && <Badge variant="neutral">Created by the billing run</Badge>}
                    </>
                }
                actions={<InvoiceActions invoice={invoice} />}
            />

            <StatusAlert invoice={invoice} />

            <StatGrid>
                <StatCard
                    label="Total"
                    value={invoice.total}
                    hint={invoice.vatRate !== '0.00' ? `Includes ${taxName()} at ${invoice.document.vatRate}` : `No ${taxName()}`}
                    icon={MoneyIcon}
                    tone="neutral"
                />
                <StatCard
                    label="Paid"
                    value={invoice.amountPaid}
                    hint={
                        invoice.amountCredited !== formatMoney(0)
                            ? `Plus ${invoice.amountCredited} credited`
                            : invoice.paidAt
                              ? `In full on ${formatDateTimeShort(invoice.paidAt)}`
                              : 'Nothing yet'
                    }
                    icon={Banknote}
                    tone="success"
                />
                <StatCard
                    label="Still owed"
                    value={invoice.status === 'void' ? '—' : invoice.balance}
                    hint={
                        invoice.status === 'paid'
                            ? 'Paid in full'
                            : invoice.status === 'void'
                              ? 'Void'
                              : invoice.status === 'draft'
                                ? 'Once issued'
                                : (due ?? undefined)
                    }
                    icon={Scale}
                    tone={invoice.status === 'overdue' ? 'danger' : invoice.isOpen ? 'warning' : 'neutral'}
                />
                <StatCard
                    label="Due date"
                    value={invoice.dueDate ? formatDay(invoice.dueDate) : 'Not issued'}
                    hint={invoice.issueDate ? `Issued ${formatDay(invoice.issueDate)}` : 'Set when issued'}
                    icon={CalendarClock}
                    tone={invoice.status === 'overdue' ? 'danger' : 'primary'}
                />
            </StatGrid>

            <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <InvoiceDocument doc={invoice.document} />

                <div className="grid gap-6">
                    <SectionCard title="Details">
                        <DescriptionList
                            layout="rows"
                            items={[
                                {
                                    label: 'Business',
                                    value: (
                                        <Link
                                            href={route('admin.tenants.show', { company: invoice.company.id, tab: 'billing' })}
                                            className="text-primary hover:underline"
                                        >
                                            {invoice.company.name}
                                        </Link>
                                    ),
                                },
                                { label: 'Period', value: invoice.period },
                                { label: 'Billing', value: invoice.cycle === 'yearly' ? 'Yearly' : 'Monthly' },
                                { label: 'Tills', value: String(invoice.lines.filter((line) => line.hasLicence).length) },
                                { label: 'Part-periods', value: invoice.prorated ? 'Charged by the day' : 'Whole period' },
                                { label: 'Emails to', value: invoice.recipients.length > 0 ? invoice.recipients.join(', ') : null },
                                {
                                    label: 'Last emailed',
                                    value: invoice.lastSentAt ? formatDateTimeShort(invoice.lastSentAt) : invoice.number ? 'Never' : null,
                                },
                            ]}
                        />
                    </SectionCard>

                    <SectionCard
                        title="Payments"
                        description={invoice.payments.length === 0 ? 'Nothing received on this invoice yet.' : undefined}
                        flush={invoice.payments.length > 0}
                    >
                        {invoice.payments.length > 0 ? (
                            <ul className="divide-y">
                                {invoice.payments.map((payment) => (
                                    <li key={payment.id}>
                                        <Link
                                            href={route('admin.billing.payments.show', payment.paymentId)}
                                            className="hover:bg-muted/50 flex items-center justify-between gap-3 px-5 py-3 text-sm sm:px-6"
                                        >
                                            <span className="min-w-0 leading-tight">
                                                <span className="block font-mono text-[13px] font-medium">{payment.number}</span>
                                                <span className="text-muted-foreground block text-xs">
                                                    {payment.method} · {formatDateTimeShort(payment.receivedAt)}
                                                </span>
                                            </span>
                                            <span className="flex shrink-0 items-center gap-2">
                                                {payment.released && <Badge variant="neutral">Moved to credit</Badge>}
                                                <span
                                                    className={
                                                        payment.released
                                                            ? 'text-muted-foreground tabular-nums line-through'
                                                            : 'font-semibold tabular-nums'
                                                    }
                                                >
                                                    {payment.amount}
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                {invoice.can.recordPayment
                                    ? 'Use “Record payment” when the money comes in.'
                                    : 'Payments recorded against it appear here.'}
                            </p>
                        )}
                    </SectionCard>

                    <SectionCard title="Activity">
                        <BillingActivity rows={activity} />
                    </SectionCard>
                </div>
            </div>
        </AdminLayout>
    );
}

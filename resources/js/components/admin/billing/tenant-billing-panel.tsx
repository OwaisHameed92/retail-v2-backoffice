import { BillingSettingsDialog } from '@/components/admin/billing/billing-settings-dialog';
import { CreateInvoiceDialog } from '@/components/admin/billing/create-invoice-dialog';
import { DirectDebitPanel } from '@/components/admin/billing/direct-debit-panel';
import { formatDate, formatDay } from '@/components/admin/billing/format';
import { DueDate, InvoiceNumber, Money } from '@/components/admin/billing/invoice-columns';
import { InvoiceStatusBadge } from '@/components/admin/billing/invoice-status-badge';
import { PricingPanel } from '@/components/admin/billing/pricing-panel';
import { RecordPaymentDialog } from '@/components/admin/billing/record-payment-dialog';
import { TenantBillingStatus } from '@/components/admin/billing/tenant-billing-status';
import { type TenantBillingData } from '@/components/admin/billing/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DescriptionList } from '@/components/shared/description-list';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { taxName, taxText } from '@/lib/country';
import { Link, router } from '@inertiajs/react';
import { AlarmClock, ArrowRight, Banknote, CalendarRange, FilePlus2, PauseCircle, PiggyBank, Receipt, Scale, Settings2 } from 'lucide-react';
import { type KeyboardEvent, useState } from 'react';

interface TenantBillingPanelProps {
    tenant: { id: string; name: string; status: string; legalName?: string | null; address?: string | null };
    billing: TenantBillingData;
}

type Dialog = 'invoice' | 'payment' | 'settings' | 'credit' | null;

function openInvoice(id: string) {
    router.visit(route('admin.billing.invoices.show', id));
}

function rowKey(event: KeyboardEvent<HTMLTableRowElement>, action: () => void) {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        action();
    }
}

/** The Billing tab of a tenant: balance, billing settings, invoices and payments, with create and record actions. */
export function TenantBillingPanel({ tenant, billing }: TenantBillingPanelProps) {
    const [dialog, setDialog] = useState<Dialog>(null);
    const close = (open: boolean) => !open && setDialog(null);
    const { settings, summary, invoices, payments, canManage } = billing;
    const company = { id: tenant.id, name: tenant.name };
    const cancelled = tenant.status === 'cancelled';

    return (
        <div className="flex flex-col gap-6">
            {summary.suspendedForBilling && (
                <Alert variant="destructive">
                    <PauseCircle className="size-4" />
                    <AlertTitle>Suspended for non-payment</AlertTitle>
                    <AlertDescription>The tills are locked. Recording the overdue payment lifts the suspension straight away.</AlertDescription>
                </Alert>
            )}

            <TenantBillingStatus company={company} billing={billing} />

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-muted-foreground text-sm">
                    Billed {settings.cycle === 'yearly' ? 'yearly' : 'monthly'}, payment due{' '}
                    {settings.paymentTermsDays === 0 ? 'on receipt' : `within ${settings.paymentTermsDays} days`}. Next period{' '}
                    {summary.nextPeriod.label}.
                </p>
                {canManage && (
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" onClick={() => setDialog('settings')}>
                            <Settings2 />
                            Settings
                        </Button>
                        {!cancelled && (
                            <Button variant="outline" size="sm" onClick={() => setDialog('invoice')}>
                                <FilePlus2 />
                                Create invoice
                            </Button>
                        )}
                        <Button size="sm" onClick={() => setDialog('payment')}>
                            <Banknote />
                            Record payment
                        </Button>
                    </div>
                )}
            </div>

            <StatGrid>
                <StatCard
                    label="Balance due"
                    value={summary.balance}
                    hint={summary.openCount === 0 ? 'Nothing owed' : `On ${summary.openCount === 1 ? '1 invoice' : `${summary.openCount} invoices`}`}
                    icon={Scale}
                    tone={summary.hasBalance ? 'warning' : 'neutral'}
                />
                <StatCard
                    label="Overdue"
                    value={summary.overdue}
                    hint={
                        summary.overdueCount === 0
                            ? 'Nothing overdue'
                            : `${summary.overdueCount === 1 ? '1 invoice' : `${summary.overdueCount} invoices`} past due`
                    }
                    icon={AlarmClock}
                    tone={summary.overdueCount > 0 ? 'danger' : 'neutral'}
                />
                <StatCard
                    label="Credit"
                    value={summary.credit}
                    hint={
                        summary.hasCredit && canManage && summary.hasBalance ? (
                            <button type="button" className="text-primary font-medium hover:underline" onClick={() => setDialog('credit')}>
                                Apply to open invoices
                            </button>
                        ) : summary.hasCredit ? (
                            'Used on the next invoice'
                        ) : (
                            'No unused payments'
                        )
                    }
                    icon={PiggyBank}
                    tone="success"
                />
                <StatCard
                    label="Last payment"
                    value={summary.lastPayment ? summary.lastPayment.amount : '—'}
                    hint={summary.lastPayment ? `${summary.lastPayment.method} · ${formatDate(summary.lastPayment.receivedAt)}` : 'No payments yet'}
                    icon={Banknote}
                    tone="primary"
                />
            </StatGrid>

            <PricingPanel company={company} directDebit={billing.directDebit} canManage={canManage} />

            <DirectDebitPanel company={company} directDebit={billing.directDebit} canManage={canManage} />

            <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="grid gap-6">
                    <SectionCard
                        title="Invoices"
                        description={
                            invoices.total > 0 ? `${invoices.total} ${invoices.total === 1 ? 'invoice' : 'invoices'}, newest first.` : undefined
                        }
                        flush={invoices.data.length > 0}
                        footer={
                            invoices.total > invoices.data.length ? (
                                <Link
                                    href={route('admin.billing.invoices.index', { company: tenant.id })}
                                    className="text-primary inline-flex items-center gap-1 font-medium hover:underline"
                                >
                                    View all {invoices.total} invoices
                                    <ArrowRight className="size-3.5" aria-hidden />
                                </Link>
                            ) : undefined
                        }
                    >
                        {invoices.data.length === 0 ? (
                            <EmptyState
                                icon={Receipt}
                                size="sm"
                                title="No invoices yet"
                                body={`The next invoice covers ${summary.nextPeriod.label}: one line per active till at its plan price.`}
                                action={
                                    canManage && !cancelled ? (
                                        <Button size="sm" onClick={() => setDialog('invoice')}>
                                            <FilePlus2 />
                                            Create invoice
                                        </Button>
                                    ) : undefined
                                }
                            />
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead>Invoice</TableHead>
                                            <TableHead className="hidden md:table-cell">Period</TableHead>
                                            <TableHead className="text-right">Total</TableHead>
                                            <TableHead className="text-right">Balance</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="hidden sm:table-cell">Due</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {invoices.data.map((row) => (
                                            <TableRow
                                                key={row.id}
                                                tabIndex={0}
                                                onClick={() => openInvoice(row.id)}
                                                onKeyDown={(event) => rowKey(event, () => openInvoice(row.id))}
                                                className="focus-visible:bg-muted/60 cursor-pointer outline-none"
                                            >
                                                <TableCell>
                                                    <InvoiceNumber row={row} />
                                                    <div className="text-muted-foreground text-xs md:hidden">{row.period}</div>
                                                </TableCell>
                                                <TableCell className="hidden whitespace-nowrap md:table-cell">{row.period}</TableCell>
                                                <TableCell className="text-right">
                                                    <Money value={row.total} />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Money value={row.isOpen ? row.balance : '—'} muted={!row.isOpen} strong={row.isOpen} />
                                                </TableCell>
                                                <TableCell>
                                                    <InvoiceStatusBadge status={row.status} />
                                                </TableCell>
                                                <TableCell className="hidden sm:table-cell">
                                                    <DueDate row={row} />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </SectionCard>

                    <SectionCard
                        title="Payments"
                        flush={payments.data.length > 0}
                        footer={
                            payments.total > payments.data.length ? (
                                <Link
                                    href={route('admin.billing.payments.index', { company: tenant.id })}
                                    className="text-primary inline-flex items-center gap-1 font-medium hover:underline"
                                >
                                    View all {payments.total} payments
                                    <ArrowRight className="size-3.5" aria-hidden />
                                </Link>
                            ) : undefined
                        }
                    >
                        {payments.data.length === 0 ? (
                            <EmptyState
                                icon={Banknote}
                                size="sm"
                                title="No payments yet"
                                body="Cash and bank transfers appear here once recorded."
                                action={
                                    canManage ? (
                                        <Button size="sm" variant="outline" onClick={() => setDialog('payment')}>
                                            <Banknote />
                                            Record payment
                                        </Button>
                                    ) : undefined
                                }
                            />
                        ) : (
                            <ul className="divide-y">
                                {payments.data.map((payment) => (
                                    <li key={payment.id}>
                                        <Link
                                            href={route('admin.billing.payments.show', payment.id)}
                                            className="hover:bg-muted/50 flex items-center justify-between gap-3 px-5 py-3 text-sm sm:px-6"
                                        >
                                            <span className="min-w-0 leading-tight">
                                                <span className="block">
                                                    <span className="font-mono text-[13px] font-medium">{payment.number}</span>
                                                    <span className="text-muted-foreground"> · {payment.methodLabel}</span>
                                                </span>
                                                <span className="text-muted-foreground block truncate text-xs">
                                                    {formatDate(payment.receivedAt)}
                                                    {payment.invoices.length > 0 &&
                                                        ` · ${payment.invoices.map((invoice) => invoice.number).join(', ')}`}
                                                    {payment.hasCredit && ` · ${payment.unallocated} credit`}
                                                </span>
                                            </span>
                                            <span className="text-success-foreground shrink-0 font-semibold tabular-nums">{payment.amount}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </div>

                <SectionCard
                    title="Billing details"
                    actions={
                        canManage ? (
                            <Button variant="ghost" size="sm" onClick={() => setDialog('settings')}>
                                Edit
                            </Button>
                        ) : undefined
                    }
                >
                    <DescriptionList
                        layout="rows"
                        items={[
                            { label: 'Bill to', value: settings.effectiveName },
                            { label: 'Address', value: settings.effectiveAddress },
                            {
                                label: 'Invoices go to',
                                value:
                                    settings.recipients.length > 0 ? (
                                        <span className="grid">
                                            {settings.recipients.map((email) => (
                                                <span key={email} className="truncate">
                                                    {email}
                                                </span>
                                            ))}
                                            {settings.recipientsAreOwners && <span className="text-muted-foreground text-xs">The owners</span>}
                                        </span>
                                    ) : (
                                        <span className="text-warning-foreground">Nobody: add a billing email</span>
                                    ),
                            },
                            { label: 'Billing', value: settings.cycle === 'yearly' ? 'Yearly' : 'Monthly' },
                            { label: 'Payment terms', value: settings.paymentTermsDays === 0 ? 'On receipt' : `${settings.paymentTermsDays} days` },
                            {
                                label: taxName(),
                                value: !billing.vatEnabled
                                    ? taxText('Not charged (not VAT registered)')
                                    : settings.vatApplies
                                      ? `Charged at ${billing.vatRate}`
                                      : 'Not charged',
                            },
                            {
                                label: 'Next period',
                                value: (
                                    <span className="inline-flex items-center gap-1.5">
                                        <CalendarRange className="text-muted-foreground size-3.5" aria-hidden />
                                        {formatDay(summary.nextPeriod.start)} – {formatDay(summary.nextPeriod.end)}
                                    </span>
                                ),
                            },
                        ]}
                    />
                </SectionCard>
            </div>

            {canManage && (
                <>
                    <CreateInvoiceDialog
                        open={dialog === 'invoice'}
                        onOpenChange={close}
                        company={company}
                        periodStart={summary.nextPeriod.start}
                        cycle={settings.cycle}
                        cycles={billing.options.cycles}
                    />
                    <RecordPaymentDialog
                        open={dialog === 'payment'}
                        onOpenChange={close}
                        company={company}
                        methods={billing.options.methods}
                        invoices={billing.openInvoices}
                    />
                    <BillingSettingsDialog
                        open={dialog === 'settings'}
                        onOpenChange={close}
                        company={{ ...company, legalName: tenant.legalName, address: tenant.address }}
                        billing={billing}
                    />
                    <ConfirmDialog
                        open={dialog === 'credit'}
                        onOpenChange={close}
                        title={`Apply ${summary.credit} of credit?`}
                        description="It is put on the open invoices, oldest first. Any invoice that ends up paid renews its tills."
                        confirmLabel="Apply credit"
                        onConfirm={() =>
                            new Promise<void>((resolve) =>
                                router.post(
                                    route('admin.billing.tenants.apply-credit', tenant.id),
                                    {},
                                    { preserveScroll: true, onFinish: () => resolve() },
                                ),
                            )
                        }
                    />
                </>
            )}
        </div>
    );
}

export default TenantBillingPanel;

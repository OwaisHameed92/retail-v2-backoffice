import { BillingTabs } from '@/components/admin/billing/billing-tabs';
import { DirectDebitOverview } from '@/components/admin/billing/direct-debit-overview';
import { formatDate, plural } from '@/components/admin/billing/format';
import { DueDate, InvoiceNumber } from '@/components/admin/billing/invoice-columns';
import { type BillingOverviewProps, type InvoiceRow } from '@/components/admin/billing/types';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link, router } from '@inertiajs/react';
import { AlarmClock, ArrowRight, Banknote, CalendarClock, CircleCheck, FileClock, FilePen, PauseCircle, PoundSterling, Receipt } from 'lucide-react';
import { type KeyboardEvent } from 'react';

function open(id: string) {
    router.visit(route('admin.billing.invoices.show', id));
}

function onKey(event: KeyboardEvent<HTMLLIElement>, id: string) {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        open(id);
    }
}

/** A compact invoice list for the overview cards: number + business, amount, due. */
function InvoiceList({ rows, amount = 'balance' }: { rows: InvoiceRow[]; amount?: 'balance' | 'total' }) {
    return (
        <ul className="divide-y">
            {rows.map((row) => (
                <li
                    key={row.id}
                    tabIndex={0}
                    onClick={() => open(row.id)}
                    onKeyDown={(event) => onKey(event, row.id)}
                    className="hover:bg-muted/50 focus-visible:bg-muted/60 flex cursor-pointer items-center gap-4 px-5 py-3 outline-none sm:px-6"
                >
                    <div className="min-w-0 flex-1 leading-tight">
                        <div className="flex items-center gap-2">
                            <InvoiceNumber row={row} />
                            <span className="text-foreground truncate text-sm font-medium">{row.company.name}</span>
                        </div>
                        <div className="text-muted-foreground mt-0.5 truncate text-xs">{row.period}</div>
                    </div>
                    <div className="hidden sm:block">
                        <DueDate row={row} />
                    </div>
                    <div className="w-24 shrink-0 text-right text-sm font-semibold tabular-nums">{amount === 'balance' ? row.balance : row.total}</div>
                </li>
            ))}
        </ul>
    );
}

function ViewAll({ href, label }: { href: string; label: string }) {
    return (
        <Link href={href} className="text-primary inline-flex items-center gap-1 font-medium hover:underline">
            {label}
            <ArrowRight className="size-3.5" aria-hidden />
        </Link>
    );
}

export default function BillingOverview({ stats, overdue, dueSoon, drafts, recentPayments, suspended, settings, directDebit }: BillingOverviewProps) {
    const nothing = stats.cashDue.count === 0 && stats.drafts.count === 0 && recentPayments.length === 0;

    return (
        <AdminLayout>
            <Head title="Billing" />

            <PageHeader
                title="Billing"
                description="Cash owed by customers, what is overdue and what came in. Payments renew the tills they pay for."
                actions={
                    <Button asChild variant="outline">
                        <Link href={route('admin.billing.invoices.index', { status: 'open' })}>
                            <Receipt />
                            Unpaid invoices
                        </Link>
                    </Button>
                }
                tabs={<BillingTabs />}
            />

            <StatGrid>
                <StatCard
                    label="Cash due"
                    value={stats.cashDue.amount}
                    hint={stats.cashDue.count === 0 ? 'Nothing owed' : `On ${plural(stats.cashDue.count, 'invoice')}`}
                    icon={PoundSterling}
                    href={route('admin.billing.invoices.index', { status: 'open' })}
                />
                <StatCard
                    label="Overdue"
                    value={stats.overdue.amount}
                    hint={stats.overdue.count === 0 ? 'All invoices on time' : plural(stats.overdue.count, 'invoice')}
                    icon={AlarmClock}
                    tone={stats.overdue.count > 0 ? 'danger' : 'neutral'}
                    href={route('admin.billing.invoices.index', { status: 'overdue' })}
                />
                <StatCard
                    label="Due this week"
                    value={stats.dueThisWeek.amount}
                    hint={stats.dueThisWeek.count === 0 ? 'Nothing due in the next 7 days' : plural(stats.dueThisWeek.count, 'invoice')}
                    icon={CalendarClock}
                    tone="warning"
                />
                <StatCard
                    label="Collected this month"
                    value={stats.collectedThisMonth.amount}
                    hint={stats.collectedThisMonth.count === 0 ? 'No payments yet' : plural(stats.collectedThisMonth.count, 'payment')}
                    icon={Banknote}
                    tone="success"
                    href={route('admin.billing.payments.index')}
                />
            </StatGrid>

            <DirectDebitOverview data={directDebit} />

            {nothing ? (
                <SectionCard>
                    <EmptyState
                        icon={Receipt}
                        title="No invoices yet"
                        body={`Create an invoice from a tenant’s Billing tab. The daily billing run also drafts invoices ${settings.generateDaysBefore} days before a customer’s tills run out.`}
                        action={
                            <Button asChild>
                                <Link href={route('admin.tenants.index')}>Go to tenants</Link>
                            </Button>
                        }
                    />
                </SectionCard>
            ) : (
                <div className="grid items-start gap-6 xl:grid-cols-2">
                    <SectionCard
                        title={
                            <span className="inline-flex items-center gap-2">
                                Overdue
                                {stats.overdue.count > 0 && <Badge variant="danger">{stats.overdue.count}</Badge>}
                            </span>
                        }
                        description={`Customers are suspended ${settings.suspendAfterDays} days after the due date. A payment lifts it straight away.`}
                        flush
                        footer={stats.overdue.count > overdue.length ? <ViewAll href={route('admin.billing.invoices.index', { status: 'overdue' })} label={`View all ${stats.overdue.count}`} /> : undefined}
                    >
                        {overdue.length === 0 ? (
                            <EmptyState icon={CircleCheck} tone="success" size="sm" title="Nothing overdue" body="Every issued invoice is within its payment terms." />
                        ) : (
                            <InvoiceList rows={overdue} />
                        )}
                    </SectionCard>

                    <SectionCard
                        title="Due in the next 7 days"
                        description="Worth a reminder call before the due date."
                        flush
                        footer={stats.dueThisWeek.count > dueSoon.length ? <ViewAll href={route('admin.billing.invoices.index', { status: 'open' })} label="View unpaid invoices" /> : undefined}
                    >
                        {dueSoon.length === 0 ? (
                            <EmptyState icon={CalendarClock} size="sm" title="Nothing due this week" body="Invoices due in the next 7 days appear here." />
                        ) : (
                            <InvoiceList rows={dueSoon} />
                        )}
                    </SectionCard>

                    <SectionCard
                        title={
                            <span className="inline-flex items-center gap-2">
                                Drafts to review
                                {stats.drafts.count > 0 && <Badge variant="neutral">{stats.drafts.count}</Badge>}
                            </span>
                        }
                        description={
                            settings.autoIssue
                                ? 'The billing run issues invoices straight away; drafts here were made by hand.'
                                : `The billing run drafts invoices ${settings.generateDaysBefore} days before tills run out. Check and issue them.`
                        }
                        flush
                        footer={stats.drafts.count > drafts.length ? <ViewAll href={route('admin.billing.invoices.index', { status: 'draft' })} label={`View all ${stats.drafts.count}`} /> : undefined}
                    >
                        {drafts.length === 0 ? (
                            <EmptyState icon={FilePen} size="sm" title="No drafts" body="Everything is issued." />
                        ) : (
                            <InvoiceList rows={drafts} amount="total" />
                        )}
                    </SectionCard>

                    <SectionCard
                        title="Recent payments"
                        description={stats.creditHeld !== '£0.00' ? `${stats.creditHeld} is held as customer credit.` : 'Cash, bank transfers and other payments recorded by staff.'}
                        flush
                        footer={<ViewAll href={route('admin.billing.payments.index')} label="All payments" />}
                    >
                        {recentPayments.length === 0 ? (
                            <EmptyState icon={Banknote} size="sm" title="No payments yet" body="Record a payment from an invoice or a tenant’s Billing tab." />
                        ) : (
                            <ul className="divide-y">
                                {recentPayments.map((payment) => (
                                    <li key={payment.id}>
                                        <Link
                                            href={route('admin.billing.payments.show', payment.id)}
                                            className="hover:bg-muted/50 focus-visible:bg-muted/60 flex items-center gap-4 px-5 py-3 outline-none sm:px-6"
                                        >
                                            <div className="min-w-0 flex-1 leading-tight">
                                                <div className="truncate text-sm font-medium">{payment.company.name}</div>
                                                <div className="text-muted-foreground mt-0.5 truncate text-xs">
                                                    {payment.methodLabel} · {formatDate(payment.receivedAt)} · <span className="font-mono">{payment.number}</span>
                                                </div>
                                            </div>
                                            <div className="text-success-foreground shrink-0 text-sm font-semibold tabular-nums">{payment.amount}</div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </div>
            )}

            {suspended.length > 0 && (
                <SectionCard
                    title={
                        <span className="inline-flex items-center gap-2">
                            <PauseCircle className="text-destructive size-4" aria-hidden />
                            Suspended for non-payment
                        </span>
                    }
                    description="Their tills are locked. Recording the overdue payment lifts the suspension automatically."
                    flush
                >
                    <ul className="divide-y">
                        {suspended.map((company) => (
                            <li key={company.companyId} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm sm:px-6">
                                <Link href={route('admin.tenants.show', { company: company.companyId, tab: 'billing' })} className="font-medium hover:underline">
                                    {company.name}
                                </Link>
                                <span className="text-muted-foreground inline-flex items-center gap-3">
                                    Since {formatDate(company.since)}
                                    {company.invoiceId && (
                                        <Link href={route('admin.billing.invoices.show', company.invoiceId)} className="text-primary inline-flex items-center gap-1 hover:underline">
                                            <FileClock className="size-3.5" aria-hidden />
                                            Invoice
                                        </Link>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            )}
        </AdminLayout>
    );
}

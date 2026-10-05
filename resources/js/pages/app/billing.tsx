import { formatDate, formatDay } from '@/components/admin/billing/format';
import { InvoiceStatusBadge } from '@/components/admin/billing/invoice-status-badge';
import { AiUsageCard } from '@/components/app/billing/ai-usage-card';
import { BillingRequestDialog } from '@/components/app/billing/billing-request-dialog';
import { DirectDebitCard } from '@/components/app/billing/direct-debit-card';
import { PaymentsCard, SetupFeeCard, SubscriptionCard } from '@/components/app/billing/subscription-cards';
import { type BillingRequestKind, type PortalBillingProps } from '@/components/app/billing/types';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { CalendarClock, Download, FileText, PoundSterling, Receipt, Tags } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My subscription', href: '/app/billing' }];

/**
 * Modules 1.13 and 4.10, My subscription (owner and accountant): plan, pricing, what is counted, Direct Debit, the
 * setup fee, payments and invoices. The owner can ask Switch & Save to cancel or to change the bank account.
 */
export default function Billing(props: PortalBillingProps) {
    const { plan, pricing, upfront, directDebit, invoices, account, payments, collections, setupFee, requests, canRequest } = props;
    const [asking, setAsking] = useState<{ kind: BillingRequestKind; key: number } | null>(null);
    const ask = (kind: BillingRequestKind) => setAsking({ kind, key: Date.now() });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My subscription" />

            <PageHeader title="My subscription" description="Your plan, how you pay, your payments and your invoices from Switch & Save." />

            <DirectDebitCard directDebit={directDebit} pricing={pricing} onChangeBank={canRequest ? () => ask('changeBank') : undefined} />

            <StatGrid>
                <StatCard
                    label="Plan"
                    value={plan ?? 'Standard'}
                    hint={pricing.unitPrice ? `${pricing.unitPrice} per ${pricing.unit} ${pricing.per}, before VAT` : pricing.modeLabel}
                    icon={Tags}
                />
                <StatCard
                    label={pricing.cycle === 'yearly' ? 'Each year' : 'Each month'}
                    value={pricing.isZero ? '£0.00' : pricing.gross}
                    hint={
                        pricing.isZero
                            ? 'Nothing to pay each cycle'
                            : `${pricing.unitsLabel}${pricing.vatApplies ? `, incl. ${pricing.vat} VAT` : ''}`
                    }
                    icon={PoundSterling}
                    tone="success"
                />
                <StatCard
                    label="Setup fee"
                    value={upfront.status === 'none' ? (upfront.recorded ? (upfront.amount ?? '£0.00') : '—') : upfront.total}
                    hint={
                        upfront.status === 'paid'
                            ? `Paid${upfront.method ? ` by ${upfront.method.toLowerCase()}` : ''}${upfront.recordedAt ? ` · ${formatDate(upfront.recordedAt)}` : ''}`
                            : upfront.status === 'none'
                              ? 'Nothing to pay'
                              : `${upfront.statusLabel} · ${upfront.owed} to pay by cash, card or bank transfer`
                    }
                    icon={Receipt}
                    tone={upfront.status === 'unpaid' ? 'warning' : 'neutral'}
                />
                <StatCard
                    label="Next collection"
                    value={directDebit.nextCollection ? directDebit.nextCollection.amount : '—'}
                    hint={directDebit.nextCollection ? `By Direct Debit on ${formatDay(directDebit.nextCollection.date)}` : 'No collection scheduled'}
                    icon={CalendarClock}
                    tone="neutral"
                />
            </StatGrid>

            <SubscriptionCard
                account={account}
                plan={plan}
                pricing={pricing}
                requests={requests}
                canRequest={canRequest}
                onCancel={() => ask('cancel')}
            />

            {setupFee && <SetupFeeCard setupFee={setupFee} />}

            <PaymentsCard payments={payments} collections={collections} />

            {props.aiUsage && <AiUsageCard usage={props.aiUsage} />}

            <SectionCard title="Invoices" description="Every invoice we have sent you. Download a copy as a PDF." flush contentClassName="p-0">
                {invoices.length === 0 ? (
                    <EmptyState icon={FileText} title="No invoices yet" body="Your invoices appear here as soon as we send them." className="m-5" />
                ) : (
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-5 sm:pl-6">Invoice</TableHead>
                                    <TableHead className="hidden md:table-cell">For</TableHead>
                                    <TableHead className="hidden sm:table-cell">Due</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="pr-5 text-right sm:pr-6">
                                        <span className="sr-only">Download</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invoices.map((invoice) => (
                                    <TableRow key={invoice.id}>
                                        <TableCell className="pl-5 sm:pl-6">
                                            <div className="font-medium">{invoice.number}</div>
                                            <div className="text-muted-foreground text-xs">Issued {formatDay(invoice.issueDate)}</div>
                                        </TableCell>
                                        <TableCell className="hidden md:table-cell">
                                            <div>{invoice.kind}</div>
                                            {invoice.period && <div className="text-muted-foreground text-xs">{invoice.period}</div>}
                                        </TableCell>
                                        <TableCell className="hidden sm:table-cell">{formatDay(invoice.dueDate)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{invoice.total}</TableCell>
                                        <TableCell>
                                            <InvoiceStatusBadge status={invoice.status} />
                                        </TableCell>
                                        <TableCell className="pr-5 text-right sm:pr-6">
                                            <Button variant="ghost" size="sm" asChild>
                                                <a
                                                    href={route('app.billing.invoices.pdf', invoice.id)}
                                                    aria-label={`Download ${invoice.number} as PDF`}
                                                >
                                                    <Download />
                                                    <span className="hidden sm:inline">PDF</span>
                                                </a>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </SectionCard>

            {asking && <BillingRequestDialog key={asking.key} kind={asking.kind} open onOpenChange={(o) => !o && setAsking(null)} />}
        </AppLayout>
    );
}

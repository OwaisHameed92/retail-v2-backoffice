import { formatDate, formatDay } from '@/components/admin/billing/format';
import { InvoiceStatusBadge } from '@/components/admin/billing/invoice-status-badge';
import { type InvoiceStatus } from '@/components/admin/billing/types';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CalendarClock, CheckCircle2, Download, FileText, Landmark, LoaderCircle, PoundSterling, Receipt, Tags, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

interface PortalBillingProps {
    businessName: string;
    plan: string | null;
    pricing: {
        mode: 'perTill' | 'perBranch';
        modeLabel: string;
        unit: string;
        unitPrice: string | null;
        units: number;
        unitsLabel: string;
        tills: number;
        cycle: 'monthly' | 'yearly';
        per: string;
        net: string;
        vat: string;
        gross: string;
        vatApplies: boolean;
        isZero: boolean;
    };
    upfront: { recorded: boolean; amount: string | null; method: string | null; recordedAt: string | null };
    directDebit: {
        directDebit: boolean;
        available: boolean;
        mandate: { usable: boolean; status: string | null; statusLabel: string; activeAt: string | null; lost: boolean };
        nextCollection: { date: string; amount: string } | null;
        deadline: { deadline: string; daysLeft: number; passed: boolean } | null;
        canSetUp: boolean;
    };
    invoices: {
        id: string;
        number: string | null;
        kind: string;
        period: string | null;
        issueDate: string | null;
        dueDate: string | null;
        total: string;
        balance: string;
        status: InvoiceStatus;
        statusLabel: string;
    }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Billing', href: '/app/billing' }];

/** Module 1.13: the business's plan, pricing, upfront payment, Direct Debit and invoices (owner and accountant). */
export default function Billing({ plan, pricing, upfront, directDebit, invoices }: PortalBillingProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Billing" />

            <PageHeader title="Billing" description="Your plan, how you pay and your invoices from Switch & Save." />

            <DirectDebitCard directDebit={directDebit} pricing={pricing} />

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
                    label="Paid upfront"
                    value={upfront.recorded ? (upfront.amount ?? '£0.00') : '—'}
                    hint={upfront.recorded && upfront.recordedAt ? `${upfront.method ?? ''} · ${formatDate(upfront.recordedAt)}` : 'Nothing recorded'}
                    icon={Receipt}
                    tone="neutral"
                />
                <StatCard
                    label="Next collection"
                    value={directDebit.nextCollection ? directDebit.nextCollection.amount : '—'}
                    hint={directDebit.nextCollection ? `By Direct Debit on ${formatDay(directDebit.nextCollection.date)}` : 'No collection scheduled'}
                    icon={CalendarClock}
                    tone="neutral"
                />
            </StatGrid>

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
        </AppLayout>
    );
}

function DirectDebitCard({ directDebit, pricing }: Pick<PortalBillingProps, 'directDebit' | 'pricing'>) {
    const [starting, setStarting] = useState(false);
    const { mandate, deadline } = directDebit;

    const start = () => router.post(route('app.billing.direct-debit'), {}, { onStart: () => setStarting(true), onFinish: () => setStarting(false) });

    const button = directDebit.canSetUp && (
        <Button onClick={start} disabled={starting || !directDebit.available}>
            {starting ? <LoaderCircle className="size-4 animate-spin" /> : <Landmark />}
            Set up Direct Debit
        </Button>
    );

    return (
        <SectionCard
            title="Direct Debit"
            description={
                directDebit.directDebit
                    ? 'Your subscription is collected by Direct Debit through GoCardless, a secure payment provider.'
                    : 'You pay each invoice by cash or bank transfer.'
            }
            actions={mandate.usable ? <StatusBadge status="active" label="Active" /> : undefined}
        >
            {mandate.usable ? (
                <div className="flex items-start gap-3 text-sm">
                    <CheckCircle2 className="text-success-foreground mt-0.5 size-5 shrink-0" aria-hidden />
                    <p>
                        Your Direct Debit is set up{mandate.activeAt ? ` since ${formatDate(mandate.activeAt)}` : ''}. We collect{' '}
                        {pricing.isZero ? 'nothing each cycle for now' : `${pricing.gross} ${pricing.per}`} and email each invoice before it is taken.
                    </p>
                </div>
            ) : !directDebit.directDebit ? (
                <p className="text-muted-foreground text-sm">
                    Want to pay by Direct Debit instead? Contact Switch & Save and we will switch you over.
                </p>
            ) : (
                <div className="grid gap-4">
                    {mandate.lost ? (
                        <Alert variant="destructive">
                            <TriangleAlert className="size-4" />
                            <AlertTitle>Your Direct Debit has stopped ({mandate.statusLabel.toLowerCase()})</AlertTitle>
                            <AlertDescription>Set it up again so your payments keep going through.</AlertDescription>
                        </Alert>
                    ) : deadline ? (
                        <Alert variant={deadline.passed ? 'destructive' : 'default'}>
                            <TriangleAlert className="size-4" />
                            <AlertTitle>
                                {deadline.passed
                                    ? 'Your tills are locked until your Direct Debit is set up'
                                    : `${deadline.daysLeft} ${deadline.daysLeft === 1 ? 'day' : 'days'} left to set up your Direct Debit`}
                            </AlertTitle>
                            <AlertDescription>
                                {deadline.passed
                                    ? 'Set it up now and your tills unlock at their next check-in.'
                                    : `Please set it up by ${formatDate(deadline.deadline)}, or your tills lock until you do.`}
                            </AlertDescription>
                        </Alert>
                    ) : pricing.isZero ? (
                        <p className="text-muted-foreground text-sm">Nothing is charged each cycle, so you do not need a Direct Debit yet.</p>
                    ) : null}
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        {button}
                        <p className="text-muted-foreground text-sm">
                            {directDebit.available
                                ? 'You will enter your bank details on GoCardless and come straight back here. It takes about two minutes.'
                                : 'Direct Debit setup is not available right now. Please try again later.'}
                        </p>
                    </div>
                </div>
            )}
        </SectionCard>
    );
}

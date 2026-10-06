import { BillingTabs } from '@/components/admin/billing/billing-tabs';
import { formatDate, formatDateTimeShort } from '@/components/admin/billing/format';
import { RecordPaymentDialog } from '@/components/admin/billing/record-payment-dialog';
import { type CompanyRef, type PaymentIndexProps, type PaymentRow } from '@/components/admin/billing/types';
import { BusinessPicker } from '@/components/admin/licences/licence-filters';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AdminLayout from '@/layouts/admin-layout';
import { formatNumber } from '@/lib/country';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Banknote, Building2, SearchX, X } from 'lucide-react';
import { useState } from 'react';

const ONLY = ['payments', 'filters', 'totals'];

const columns: ColumnDef<PaymentRow>[] = [
    {
        id: 'sequence',
        header: 'Receipt',
        enableSorting: true,
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="min-w-0 leading-tight">
                <span className="font-mono text-[13px] font-medium">{row.original.number}</span>
                <div className="text-muted-foreground mt-0.5 truncate text-xs md:hidden">{row.original.company.name}</div>
            </div>
        ),
    },
    {
        id: 'company_name',
        header: 'Business',
        enableSorting: true,
        meta: { mobile: 'hidden' },
        cell: ({ row }) => <span className="block max-w-56 truncate font-medium">{row.original.company.name}</span>,
    },
    {
        id: 'received_at',
        header: 'Received',
        enableSorting: true,
        cell: ({ row }) => (
            <time dateTime={row.original.receivedAt} title={formatDateTimeShort(row.original.receivedAt)} className="whitespace-nowrap tabular-nums">
                {formatDate(row.original.receivedAt)}
            </time>
        ),
    },
    {
        id: 'method',
        header: 'Method',
        cell: ({ row }) => row.original.methodLabel,
    },
    {
        id: 'invoices',
        header: 'Paid towards',
        cell: ({ row }) => (
            <div className="flex max-w-60 flex-wrap items-center gap-1">
                {row.original.invoices.map((invoice) => (
                    <span key={invoice.id} className="font-mono text-xs">
                        {invoice.number}
                    </span>
                ))}
                {row.original.hasCredit && <Badge variant="info">{row.original.unallocated} credit</Badge>}
                {row.original.invoices.length === 0 && !row.original.hasCredit && <span className="text-muted-foreground">—</span>}
            </div>
        ),
    },
    {
        id: 'reference',
        header: 'Reference',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => <span className="text-muted-foreground block max-w-44 truncate">{row.original.reference ?? '—'}</span>,
    },
    {
        id: 'amount',
        header: 'Amount',
        enableSorting: true,
        meta: { align: 'right', mobile: 'aside' },
        cell: ({ row }) => <span className="font-semibold whitespace-nowrap tabular-nums">{row.original.amount}</span>,
    },
];

function PaymentFilters({ filters, methods }: Pick<PaymentIndexProps, 'filters' | 'methods'>) {
    const { update } = useTableQuery({ only: ONLY });
    const [picking, setPicking] = useState(false);
    const active = Boolean(filters.method || filters.company || filters.from || filters.to);

    return (
        <>
            <Select value={filters.method ?? 'all'} onValueChange={(next) => update({ method: next === 'all' ? undefined : next, page: 1 })}>
                <SelectTrigger className="h-9 w-full sm:w-40" aria-label="Filter by method">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All methods</SelectItem>
                    {methods.map((method) => (
                        <SelectItem key={method.value} value={method.value}>
                            {method.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {filters.company ? (
                <span className="bg-accent inline-flex h-9 max-w-full items-center gap-1 rounded-md border pr-1 pl-3 text-sm">
                    <Building2 className="text-muted-foreground size-4 shrink-0" aria-hidden />
                    <span className="truncate">{filters.company.name}</span>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-label={`Stop filtering by ${filters.company.name}`}
                        onClick={() => update({ company: undefined, page: 1 })}
                    >
                        <X className="size-4" />
                    </Button>
                </span>
            ) : (
                <Button variant="outline" className="h-9 justify-start font-normal" onClick={() => setPicking(true)}>
                    <Building2 className="text-muted-foreground" />
                    All businesses
                </Button>
            )}

            <div className="flex items-center gap-2">
                <Input
                    type="date"
                    value={filters.from ?? ''}
                    max={filters.to ?? undefined}
                    onChange={(event) => update({ from: event.target.value || undefined, page: 1 })}
                    aria-label="Received from"
                    className="h-9 w-full sm:w-38"
                />
                <span className="text-muted-foreground text-sm">to</span>
                <Input
                    type="date"
                    value={filters.to ?? ''}
                    min={filters.from ?? undefined}
                    onChange={(event) => update({ to: event.target.value || undefined, page: 1 })}
                    aria-label="Received to"
                    className="h-9 w-full sm:w-38"
                />
            </div>

            {active && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-9 self-start sm:self-auto"
                    onClick={() => update({ method: undefined, company: undefined, from: undefined, to: undefined, page: 1 })}
                >
                    <X />
                    Clear filters
                </Button>
            )}

            <BusinessPicker
                open={picking}
                onOpenChange={setPicking}
                description="Show only the payments of one customer."
                onPick={(id) => {
                    setPicking(false);
                    update({ company: id, page: 1 });
                }}
            />
        </>
    );
}

export default function PaymentIndex({ payments, filters, totals, methods, manualMethods, canManage }: PaymentIndexProps) {
    const filtered = Boolean(payments.meta.search || filters.method || filters.company || filters.from || filters.to);
    const [picking, setPicking] = useState(false);
    const [paying, setPaying] = useState<CompanyRef | null>(null);

    return (
        <AdminLayout>
            <Head title="Payments" />

            <PageHeader
                title="Billing"
                description="Every payment recorded: cash, bank transfers and other. Each one is allocated to invoices, oldest first unless chosen."
                actions={
                    canManage ? (
                        <Button onClick={() => setPicking(true)}>
                            <Banknote />
                            Record payment
                        </Button>
                    ) : undefined
                }
                tabs={<BillingTabs />}
            />

            <div className="flex flex-col gap-3">
                <DataTable
                    columns={columns}
                    data={payments.data}
                    meta={payments.meta}
                    only={ONLY}
                    searchPlaceholder="Search receipt, reference or business"
                    filters={<PaymentFilters filters={filters} methods={methods} />}
                    getRowId={(row) => row.id}
                    onRowClick={(row) => router.visit(route('admin.billing.payments.show', row.id))}
                    empty={
                        filtered ? (
                            <EmptyState
                                icon={SearchX}
                                title="No payments match"
                                body="Try a different search, method, business or date range."
                                tone="neutral"
                            />
                        ) : (
                            <EmptyState
                                icon={Banknote}
                                title="No payments yet"
                                body="Record cash or a bank transfer when a customer pays. The invoice it pays is marked paid and its tills are renewed."
                                action={
                                    canManage ? (
                                        <Button onClick={() => setPicking(true)}>
                                            <Banknote />
                                            Record payment
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    }
                />

                {totals.count > 0 && (
                    <Card className="flex flex-col gap-2 px-5 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                        <span className="text-muted-foreground">
                            {formatNumber(totals.count)} {totals.count === 1 ? 'payment' : 'payments'}
                            {filtered ? ' matching the filters' : ''}
                        </span>
                        <span>
                            <span className="text-muted-foreground">Received </span>
                            <span className="text-success-foreground font-semibold tabular-nums">{totals.amount}</span>
                        </span>
                    </Card>
                )}
            </div>

            <BusinessPicker
                open={picking}
                onOpenChange={setPicking}
                title="Record a payment"
                description="Choose the customer who paid."
                onPick={(id, name) => {
                    setPicking(false);
                    setPaying({ id, name });
                }}
            />

            {paying && (
                <RecordPaymentDialog
                    open={paying !== null}
                    onOpenChange={(open) => !open && setPaying(null)}
                    company={paying}
                    methods={manualMethods}
                />
            )}
        </AdminLayout>
    );
}

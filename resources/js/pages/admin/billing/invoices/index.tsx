import { BillingTabs } from '@/components/admin/billing/billing-tabs';
import { CreateInvoiceDialog } from '@/components/admin/billing/create-invoice-dialog';
import { invoiceColumns } from '@/components/admin/billing/invoice-columns';
import { InvoiceFilters } from '@/components/admin/billing/invoice-filters';
import { type CompanyRef, type InvoiceIndexProps } from '@/components/admin/billing/types';
import { BusinessPicker } from '@/components/admin/licences/licence-filters';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useBreakpoint } from '@/hooks/use-min-width';
import AdminLayout from '@/layouts/admin-layout';
import { Head, router } from '@inertiajs/react';
import { FilePlus2, Receipt, SearchX } from 'lucide-react';
import { useMemo, useState } from 'react';

const number = new Intl.NumberFormat('en-GB');

const CYCLES = [
    { value: 'monthly' as const, label: 'Monthly' },
    { value: 'yearly' as const, label: 'Yearly' },
];

export default function InvoiceIndex({ invoices, filters, totals, statuses, counts, canManage }: InvoiceIndexProps) {
    const breakpoint = useBreakpoint();
    const columns = useMemo(() => invoiceColumns({ breakpoint, showBusiness: true }), [breakpoint]);
    const filtered = Boolean(invoices.meta.search || filters.status || filters.company || filters.from || filters.to);
    const [picking, setPicking] = useState(false);
    const [creating, setCreating] = useState<CompanyRef | null>(null);
    const all = Object.values(counts).reduce((sum, count) => sum + (count ?? 0), 0);

    return (
        <AdminLayout>
            <Head title="Invoices" />

            <PageHeader
                title="Billing"
                description={
                    all === 0
                        ? 'Invoices for every customer. One line per till, at its plan price.'
                        : `${number.format(all)} ${all === 1 ? 'invoice' : 'invoices'} across every customer. Paying one renews the tills on it.`
                }
                actions={
                    canManage ? (
                        <Button onClick={() => setPicking(true)}>
                            <FilePlus2 />
                            New invoice
                        </Button>
                    ) : undefined
                }
                tabs={<BillingTabs />}
            />

            <div className="flex flex-col gap-3">
                <DataTable
                    columns={columns}
                    data={invoices.data}
                    meta={invoices.meta}
                    only={['invoices', 'filters', 'totals', 'counts']}
                    searchPlaceholder="Search invoice number or business"
                    filters={<InvoiceFilters filters={filters} statuses={statuses} counts={counts} />}
                    getRowId={(row) => row.id}
                    onRowClick={(row) => router.visit(route('admin.billing.invoices.show', row.id))}
                    empty={
                        filtered ? (
                            <EmptyState icon={SearchX} title="No invoices match" body="Try a different search, status, business or date range." tone="neutral" />
                        ) : (
                            <EmptyState
                                icon={Receipt}
                                title="No invoices yet"
                                body="Create the first invoice for a customer. The daily billing run also drafts invoices before tills run out."
                                action={
                                    canManage ? (
                                        <Button onClick={() => setPicking(true)}>
                                            <FilePlus2 />
                                            New invoice
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
                            Totals for {number.format(totals.count)} {totals.count === 1 ? 'invoice' : 'invoices'}
                            {filtered ? ' matching the filters' : ''} (void excluded)
                        </span>
                        <dl className="flex gap-6">
                            <div className="flex items-baseline gap-2">
                                <dt className="text-muted-foreground">Total</dt>
                                <dd className="font-semibold tabular-nums">{totals.total}</dd>
                            </div>
                            <div className="flex items-baseline gap-2">
                                <dt className="text-muted-foreground">Still owed</dt>
                                <dd className="text-primary font-semibold tabular-nums">{totals.balance}</dd>
                            </div>
                        </dl>
                    </Card>
                )}
            </div>

            <BusinessPicker
                open={picking}
                onOpenChange={setPicking}
                title="New invoice"
                description="Choose the customer to invoice."
                onPick={(id, name) => {
                    setPicking(false);
                    setCreating({ id, name });
                }}
            />

            {creating && (
                <CreateInvoiceDialog open={creating !== null} onOpenChange={(open) => !open && setCreating(null)} company={creating} cycles={CYCLES} />
            )}
        </AdminLayout>
    );
}

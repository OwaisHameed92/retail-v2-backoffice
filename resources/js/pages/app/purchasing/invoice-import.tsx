import { formatDateTime, formatDay, money } from '@/components/app/purchasing/format';
import { IMPORT_TONES, type ImportRow, type InvoiceImportProps } from '@/components/app/purchasing/invoice-import/types';
import { UploadCard } from '@/components/app/purchasing/invoice-import/upload-card';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { FileSearch, Info, Lock } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['imports', 'filters'];
const dash = <span className="text-muted-foreground">—</span>;

function columns(showShop: boolean): ColumnDef<ImportRow>[] {
    return [
        {
            id: 'created_at',
            header: 'Uploaded',
            enableSorting: true,
            cell: ({ row }) => (
                <div className="grid leading-5">
                    <span>{row.original.createdAt ? formatDateTime(row.original.createdAt) : '—'}</span>
                    <span className="text-muted-foreground text-xs">
                        {row.original.method === 'manual' ? 'Entered by hand' : 'Read automatically'}
                        {row.original.uploadedBy ? ` · ${row.original.uploadedBy}` : ''}
                    </span>
                </div>
            ),
            meta: { mobile: 'title' },
        },
        {
            id: 'invoice',
            header: 'Invoice',
            cell: ({ row }) => (
                <div className="grid max-w-64 leading-5">
                    <span className="font-mono text-sm font-medium">{row.original.invoiceNumber ?? row.original.fileName ?? 'No number yet'}</span>
                    <span className="text-muted-foreground truncate text-xs">
                        {row.original.supplier ?? row.original.supplierName ?? 'Supplier not matched'}
                        {showShop && row.original.shop ? ` · ${row.original.shop}` : ''}
                    </span>
                </div>
            ),
        },
        {
            id: 'invoice_date',
            header: 'Invoice date',
            enableSorting: true,
            cell: ({ row }) => (row.original.invoiceDate ? formatDay(row.original.invoiceDate) : dash),
        },
        { id: 'lines', header: 'Lines', cell: ({ row }) => <span className="tabular-nums">{row.original.lines}</span>, meta: { align: 'right' } },
        {
            id: 'gross_total',
            header: 'Total',
            enableSorting: true,
            cell: ({ row }) => (row.original.gross ? <span className="tabular-nums">{money(row.original.gross)}</span> : dash),
            meta: { align: 'right' },
        },
        {
            id: 'status',
            header: 'Status',
            cell: ({ row }) => <StatusBadge status={row.original.status} label={row.original.statusLabel} tones={IMPORT_TONES} />,
        },
    ];
}

/** Invoice import (module 6.5): upload a supplier invoice, then review what was read before confirming. */
export default function InvoiceImportIndex({ access, shops, limits, filters, statuses, imports }: InvoiceImportProps) {
    const { update } = useTableQuery({ only: ONLY });
    const showShop = !access.oneShop && shops.length > 1;
    const cols = useMemo(() => columns(showShop), [showShop]);
    const filtered = Boolean(imports.meta.search) || Boolean(filters.status);

    return (
        <AppLayout>
            <Head title="Import an invoice · Purchasing" />

            <PageHeader
                title="Import an invoice"
                back={{ href: route('app.purchasing.index', 'invoices'), label: 'Invoices' }}
                description="Upload a supplier invoice or delivery note. We read it, match it to your supplier, products and the shop's order, and check the sums. Nothing changes until you confirm."
            />

            {access.oneShop && shops[0] && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>You import invoices for {shops[0].name}.</AlertDescription>
                </Alert>
            )}

            {access.inPlan ? (
                <UploadCard access={access} shops={shops} limits={limits} />
            ) : (
                <EmptyState
                    icon={Lock}
                    tone="neutral"
                    bordered
                    title="Invoice import is not in your plan"
                    body="Reading supplier invoices automatically comes with the invoice scanning feature. Ask us to add it to your plan; the imports below stay available."
                />
            )}

            <DataTable
                columns={cols}
                data={imports.data}
                meta={imports.meta}
                only={ONLY}
                searchPlaceholder="Search by invoice number or file name"
                filters={
                    <FilterSelect
                        value={filters.status}
                        onChange={(status) => update({ status, page: 1 })}
                        all="Any status"
                        options={statuses.map((s) => ({ value: s.value, label: s.label }))}
                        label="Filter by status"
                        width="sm:w-44"
                    />
                }
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.purchasing.invoices.import.show', row.id))}
                empty={
                    filtered ? undefined : (
                        <EmptyState icon={FileSearch} title="No invoices imported yet" body="Uploaded invoices appear here, with what was read and confirmed." />
                    )
                }
            />
        </AppLayout>
    );
}

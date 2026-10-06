import { ComplianceFilters, CompliancePageLayout } from '@/components/app/compliance/compliance-page';
import { dash, formatDateTime, formatDay, Stack } from '@/components/app/compliance/format';
import { RecallDialog } from '@/components/app/compliance/recall-dialog';
import { RecallStatus } from '@/components/app/compliance/recall-status';
import { type RecallRow, type RecallsProps } from '@/components/app/compliance/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { number } from '@/components/shared/trading/format';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { PackageX, Plus } from 'lucide-react';
import { useState } from 'react';
import { ukOnly } from '@/lib/country-text';

function dates(r: RecallRow): string | null {
    if (r.expiryFrom && r.expiryTo) return `Best before ${formatDay(r.expiryFrom)} – ${formatDay(r.expiryTo)}`;
    if (r.expiryFrom) return `Best before from ${formatDay(r.expiryFrom)}`;
    if (r.expiryTo) return `Best before up to ${formatDay(r.expiryTo)}`;

    return null;
}

const columns: ColumnDef<RecallRow>[] = [
    {
        id: 'reference',
        header: 'Recall',
        enableSorting: true,
        meta: { mobile: 'title' },
        cell: ({ row }) => <Stack main={row.original.product ?? 'Unknown product'} sub={row.original.reference} />,
    },
    {
        id: 'batch',
        header: 'Batch and dates',
        cell: ({ row }) =>
            row.original.batchCode || dates(row.original) ? (
                <Stack main={row.original.batchCode ? `Batch ${row.original.batchCode}` : 'Any batch'} sub={dates(row.original)} />
            ) : (
                'Every batch'
            ),
    },
    {
        id: 'reason',
        header: 'Reason',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => (row.original.reason ? <span className="line-clamp-2 max-w-72 text-sm">{row.original.reason}</span> : dash),
    },
    {
        id: 'onHand',
        header: 'On hand',
        meta: { align: 'right', mobile: 'hidden' },
        cell: ({ row }) =>
            row.original.onHand === null || row.original.onHand === undefined ? (
                dash
            ) : (
                <span className="tabular-nums">{number(Number(row.original.onHand))}</span>
            ),
    },
    {
        id: 'raised_at',
        header: 'Raised',
        enableSorting: true,
        meta: { mobile: 'hidden' },
        cell: ({ row }) => <span className="tabular-nums">{formatDateTime(row.original.raisedAt)}</span>,
    },
    {
        id: 'status',
        header: 'Status',
        meta: { mobile: 'aside' },
        cell: ({ row }) => <RecallStatus status={row.original.status} openShops={row.original.openShops} shops={row.original.shops} />,
    },
];

/**
 * Product recalls (module 5.7): raised on the portal (or by head office) and sent to every till; each shop closes its
 * own at the till (till 0.1.52). Status and on hand are for the shop picked, else every shop.
 */
export default function ComplianceRecalls({ recalls, summary, suppliers, productResults, canManage, filters, options }: RecallsProps) {
    const { update, loading } = useTableQuery();
    const [open, setOpen] = useState(false);

    return (
        <CompliancePageLayout
            tab="recalls"
            filters={filters}
            title="Recalls"
            description={`Product recalls sent to every till, open first; each shop closes its own at the till. ${summary.open} open, ${summary.closed} closed${summary.shops > 1 ? ' in every shop' : ''}. Status and on hand are for the shop picked.`}
            actions={
                canManage && (
                    <Button onClick={() => setOpen(true)}>
                        <Plus />
                        Raise a recall
                    </Button>
                )
            }
        >
            <DataTable
                columns={columns}
                data={recalls.data}
                meta={recalls.meta}
                onChange={update}
                loading={loading}
                searchPlaceholder="Search product, batch or reference"
                filters={
                    <ComplianceFilters filters={filters} options={options} update={update} dates={false} staff={false}>
                        <FilterSelect
                            value={filters.status}
                            onChange={(status) => update({ status, page: undefined })}
                            all="Open and closed"
                            options={[
                                { value: 'open', label: 'Open' },
                                { value: 'closed', label: 'Closed' },
                            ]}
                            label="Filter by status"
                        />
                    </ComplianceFilters>
                }
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.compliance.recalls.show', row.id))}
                empty={
                    <EmptyState
                        icon={PackageX}
                        title="No recalls"
                        body={
                            canManage
                                ? ukOnly(
                                      'Raise a recall when a supplier or the Food Standards Agency withdraws a product. Every till gets it.',
                                      'Raise a recall when a supplier or the food authority withdraws a product. Every till gets it.',
                                  )
                                : 'Recalls raised by the business appear here.'
                        }
                    />
                }
            />
            {open && <RecallDialog recall={null} suppliers={suppliers} productResults={productResults} onClose={() => setOpen(false)} />}
        </CompliancePageLayout>
    );
}

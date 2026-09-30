import { CashFilters, CashPageLayout } from '@/components/app/cash/cash-page';
import { AlertFlag, dash, formatDateTime, formatDay, number, Variance } from '@/components/app/cash/format';
import { type ZReportsProps, type ZRow } from '@/components/app/cash/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Receipt } from 'lucide-react';

const columns: ColumnDef<ZRow>[] = [
    {
        id: 'z',
        header: 'Z report',
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-medium tabular-nums">Z {row.original.sequenceNo}</span>
                <span className="text-muted-foreground text-xs">{formatDay(row.original.day)}</span>
            </div>
        ),
    },
    {
        id: 'where',
        header: 'Shop and till',
        meta: { label: 'Where' },
        cell: ({ row }) => (
            <div className="grid max-w-48 text-sm leading-5">
                <span className="truncate">{row.original.shop ?? 'Unknown shop'}</span>
                <span className="text-muted-foreground truncate text-xs">{row.original.till ?? 'Unknown till'}</span>
            </div>
        ),
    },
    { id: 'from', header: 'From', meta: { mobile: 'hidden' }, cell: ({ row }) => formatDateTime(row.original.periodStart) },
    { id: 'to', header: 'To', cell: ({ row }) => formatDateTime(row.original.periodEnd) },
    {
        id: 'printed',
        header: 'Printed',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => (row.original.printedAt ? formatDateTime(row.original.printedAt) : dash),
    },
    {
        id: 'reprints',
        header: 'Reprints',
        meta: { align: 'right', mobile: 'hidden' },
        cell: ({ row }) => <span className="tabular-nums">{number(row.original.reprints)}</span>,
    },
    {
        id: 'variance',
        header: 'Variance',
        meta: { align: 'right', mobile: 'aside' },
        cell: ({ row }) => (
            <div className="grid justify-items-end gap-0.5">
                <Variance value={row.original.variance} />
                {row.original.warning && <AlertFlag />}
            </div>
        ),
    },
];

export default function CashZReports({ reports, filters, options }: ZReportsProps) {
    const { update, loading } = useTableQuery({ only: ['reports', 'filters', 'options'] });

    return (
        <CashPageLayout
            tab="z"
            filters={filters}
            title="Z reports · Cash and Z"
            description="Each till's end-of-day Z reports, by the day their period ended. Figures are the till's own, as printed."
        >
            <DataTable
                columns={columns}
                data={reports.data}
                meta={reports.meta}
                onChange={update}
                loading={loading}
                filters={<CashFilters filters={filters} options={options} update={update} />}
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.cash.z.show', row.id))}
                empty={
                    <EmptyState
                        icon={Receipt}
                        title="No Z reports in these dates"
                        body="A Z report appears here once a till has closed its day and synced."
                    />
                }
            />
        </CashPageLayout>
    );
}

import { FilterSelect } from '@/components/app/setup/fields';
import { formatDateTime, number, Qty, signedMoney, StockTabs } from '@/components/app/stock/format';
import { StockFilters } from '@/components/app/stock/stock-filters';
import { TAKE_SCOPES, TAKE_STATUSES, TakeStatusPill } from '@/components/app/stock/take-status';
import { type TakeRow, type TakesProps } from '@/components/app/stock/types';
import { DataTable, type TableParams, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { ClipboardList, Info } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['takes', 'filters'];

/** The tills' stock takes (module 5.1), read only: started, counted and approved on the till. */
export default function StockTakes({ takes, filters, options }: TakesProps) {
    const { update, loading } = useTableQuery({ only: ONLY });
    const change = (params: TableParams) => {
        const { search, ...rest } = params;
        void search;
        update(rest);
    };

    const columns = useMemo<ColumnDef<TakeRow>[]>(
        () => [
            {
                id: 'take',
                header: 'Stock take',
                meta: { mobile: 'title' },
                cell: ({ row }) => (
                    <div className="grid leading-5">
                        <Link
                            href={route('app.stock.takes.show', row.original.id)}
                            className="font-medium hover:underline"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {row.original.name || row.original.reference || 'Stock take'}
                        </Link>
                        <span className="text-muted-foreground text-xs">
                            {[row.original.reference, TAKE_SCOPES[row.original.scope ?? ''], row.original.isHighValue ? 'High-value count' : null]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    </div>
                ),
            },
            { id: 'status', header: 'Status', meta: { mobile: 'field' }, cell: ({ row }) => <TakeStatusPill status={row.original.status} /> },
            {
                id: 'when',
                header: 'Started',
                meta: { mobile: 'field' },
                cell: ({ row }) => (
                    <div className="grid text-sm leading-5">
                        <span className="whitespace-nowrap">{formatDateTime(row.original.startedAt)}</span>
                        <span className="text-muted-foreground text-xs">
                            {row.original.shop}
                            {row.original.startedBy ? ` · ${row.original.startedBy}` : ''}
                        </span>
                    </div>
                ),
            },
            {
                id: 'counted',
                header: 'Counted',
                meta: { align: 'right', mobile: 'hidden' },
                cell: ({ row }) => (
                    <span className="tabular-nums">
                        {number(row.original.counted)} of {number(row.original.lines)}
                    </span>
                ),
            },
            {
                id: 'varianceQty',
                header: 'Units over / short',
                meta: { align: 'right', mobile: 'hidden' },
                cell: ({ row }) => <Qty value={row.original.varianceQty} signed />,
            },
            {
                id: 'varianceCost',
                header: 'Variance at cost',
                meta: { align: 'right', mobile: 'aside' },
                cell: ({ row }) => (
                    <span className={`font-medium tabular-nums ${Number(row.original.varianceCost) < 0 ? 'text-danger-foreground' : ''}`}>
                        {signedMoney(row.original.varianceCost)}
                    </span>
                ),
            },
        ],
        [],
    );

    return (
        <AppLayout>
            <Head title="Stock takes" />

            <PageHeader
                title="Stock takes"
                description="Counts done on your tills, with what was expected, what was counted and the difference at cost."
                tabs={<StockTabs active="takes" shop={filters.shopLocked ? null : filters.shop} />}
            />

            <Alert variant="info">
                <Info />
                <AlertDescription>
                    Stock takes belong to the tills: start, count and approve them on a till. They appear here once the till has synced.
                </AlertDescription>
            </Alert>

            <DataTable
                columns={columns}
                data={takes.data}
                meta={takes.meta}
                onChange={change}
                searchable={false}
                filters={
                    <div className="flex w-full flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <StockFilters filters={filters} options={options} update={update} />
                        <FilterSelect
                            value={filters.takeStatus ?? null}
                            onChange={(status) => update({ status, page: undefined })}
                            all="Every status"
                            options={TAKE_STATUSES}
                            label="Status"
                        />
                    </div>
                }
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.stock.takes.show', row.id))}
                loading={loading}
                empty={<EmptyState icon={ClipboardList} title="No stock takes" body="Stock takes appear here once a till has synced one." />}
            />
        </AppLayout>
    );
}

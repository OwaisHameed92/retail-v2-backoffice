import { ExportMenu } from '@/components/app/sales/export-menu';
import { Amount, formatDateTime, formatDay, number, SaleKindPill } from '@/components/app/sales/format';
import { SalesFilters } from '@/components/app/sales/sales-filters';
import { type SaleRow, type SalesIndexProps } from '@/components/app/sales/types';
import { currentTableParams, DataTable, type TableParams, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { ChevronLeft, ChevronRight, Receipt } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['sales', 'filters', 'options', 'exports'];
const dash = <span className="text-muted-foreground">—</span>;

/** The current query with changes applied (keyset cursors dropped unless given). */
function withParams(changes: Record<string, string | number | undefined>): Record<string, string | number> {
    const params: Record<string, string | number> = { ...currentTableParams() };
    delete params.after;
    delete params.before;
    for (const [key, value] of Object.entries(changes)) {
        if (value === undefined || value === '') {
            delete params[key];
        } else {
            params[key] = value;
        }
    }

    return params;
}

export default function SalesIndex({ sales, filters, options, exports, exportStreamLimit, canViewCustomers }: SalesIndexProps) {
    const { update, loading } = useTableQuery({ only: ONLY });
    const change = (params: TableParams) => {
        const { search, page, ...rest } = params;
        void page;
        update({ ...rest, ...('search' in params ? { receipt: search } : {}), after: undefined, before: undefined });
    };

    const countLabel = sales.capped ? `${number(sales.count)}+ sales` : `${number(sales.count)} ${sales.count === 1 ? 'sale' : 'sales'}`;
    const range =
        filters.receipt && !/^\d+$/.test(filters.receipt)
            ? 'every date'
            : filters.from === filters.to
              ? formatDay(filters.from)
              : `${formatDay(filters.from)} – ${formatDay(filters.to)}`;
    const tab = (status: string | undefined) => route('app.sales.index', withParams({ status }));

    const columns = useMemo<ColumnDef<SaleRow>[]>(
        () => [
            {
                id: 'receipt',
                header: 'Receipt',
                meta: { mobile: 'title' },
                cell: ({ row }) => (
                    <div className="grid leading-5">
                        <Link
                            href={route('app.sales.show', row.original.id)}
                            className="font-medium hover:underline"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {row.original.receiptNumber}
                        </Link>
                        <span className="text-muted-foreground text-xs">{formatDateTime(row.original.at)}</span>
                    </div>
                ),
            },
            {
                id: 'status',
                header: 'Type',
                meta: { mobile: 'field' },
                cell: ({ row }) => (
                    <div className="flex flex-wrap items-center gap-1">
                        <SaleKindPill type={row.original.type} status={row.original.status} />
                        {row.original.refunded && <StatusPill tone="warning">Refunded</StatusPill>}
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
            { id: 'staff', header: 'Staff', cell: ({ row }) => row.original.staff ?? dash },
            {
                id: 'customer',
                header: 'Customer',
                cell: ({ row }) =>
                    row.original.customer ? (
                        canViewCustomers ? (
                            <Link
                                href={route('app.customers.show', row.original.customer.id)}
                                className="block max-w-40 truncate hover:underline"
                                onClick={(e) => e.stopPropagation()}
                            >
                                {row.original.customer.name}
                            </Link>
                        ) : (
                            <span className="block max-w-40 truncate">{row.original.customer.name}</span>
                        )
                    ) : (
                        dash
                    ),
            },
            {
                id: 'payment',
                header: 'Paid by',
                cell: ({ row }) => (row.original.tenders.length ? <span className="text-sm">{row.original.tenders.join(' + ')}</span> : dash),
            },
            {
                id: 'items',
                header: 'Items',
                meta: { align: 'right', mobile: 'hidden' },
                cell: ({ row }) => <span className="tabular-nums">{number(row.original.items)}</span>,
            },
            {
                id: 'total',
                header: 'Total',
                meta: { align: 'right', mobile: 'aside' },
                cell: ({ row }) => <Amount value={row.original.total} voided={row.original.status === 'voided'} className="font-medium" />,
            },
        ],
        [canViewCustomers],
    );

    const pager = (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-muted-foreground text-sm">
                {countLabel} · {range}
            </p>
            <div className="flex items-center gap-2">
                <Select
                    value={String(sales.perPage)}
                    onValueChange={(perPage) => update({ perPage: Number(perPage), after: undefined, before: undefined })}
                >
                    <SelectTrigger className="h-8 w-28" aria-label="Rows per page">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {[25, 50, 100].map((n) => (
                            <SelectItem key={n} value={String(n)}>
                                {n} per page
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!sales.newer || loading}
                    onClick={() => update({ before: sales.newer ?? undefined, after: undefined })}
                >
                    <ChevronLeft />
                    Newer
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!sales.older || loading}
                    onClick={() => update({ after: sales.older ?? undefined, before: undefined })}
                >
                    Older
                    <ChevronRight />
                </Button>
            </div>
        </div>
    );

    return (
        <AppLayout>
            <Head title="Sales" />

            <PageHeader
                title="Sales"
                description="Every receipt your tills have sent, newest first. Sales belong to the tills, so they are read only here."
                actions={
                    <ExportMenu
                        exports={exports}
                        href={route('app.sales.export', withParams({}))}
                        count={sales.count}
                        capped={sales.capped}
                        streamLimit={exportStreamLimit}
                    />
                }
                tabs={
                    <PageTabs
                        label="Sale types"
                        tabs={[
                            { label: 'All', href: tab(undefined), active: filters.status === null },
                            { label: 'Completed sales', href: tab('completed'), active: filters.status === 'completed' },
                            { label: 'Refunds', href: tab('refunds'), active: filters.status === 'refunds' },
                            { label: 'Voided baskets', href: tab('voided'), active: filters.status === 'voided' },
                        ]}
                    />
                }
            />

            <DataTable
                columns={columns}
                data={sales.data}
                meta={{ page: 1, perPage: sales.perPage, total: sales.count, search: filters.receipt }}
                onChange={change}
                searchPlaceholder="Receipt number, e.g. LDS-01-000482"
                filters={<SalesFilters filters={filters} options={options} update={update} />}
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.sales.show', row.id))}
                loading={loading}
                footer={pager}
                empty={
                    <EmptyState
                        icon={Receipt}
                        title={filters.status === 'voided' ? 'No voided baskets' : filters.status === 'refunds' ? 'No refunds' : 'No sales found'}
                        body={
                            filters.receipt
                                ? 'No receipt starts with that number. Receipt numbers look like LDS-01-000482; a plain number finds that sale number within the dates.'
                                : 'Nothing matches these dates and filters. Sales appear here once a till has synced them.'
                        }
                    />
                }
            />
        </AppLayout>
    );
}

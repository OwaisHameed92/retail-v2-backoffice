import { money, number, Qty, StockStatusPill, StockTabs, withParams } from '@/components/app/stock/format';
import { StockFilters } from '@/components/app/stock/stock-filters';
import { type StockIndexProps, type StockRow } from '@/components/app/stock/types';
import { DataTable, type TableParams, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { AlertTriangle, Boxes, PackageX, PoundSterling } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['stock', 'summary', 'filters', 'options', 'hasStock'];

/** One shop: the line's status. Every shop: the worst, with how many shops it applies to ("Low in 2 shops"). */
function RowStatus({ row, everyShop }: { row: StockRow; everyShop: boolean }) {
    if (!everyShop || row.status === 'ok') {
        return <StockStatusPill status={row.status} />;
    }

    const count = row.status === 'negative' ? row.negativeShops : row.status === 'out' ? row.outShops : row.lowShops;
    const label = row.status === 'negative' ? 'Negative' : row.status === 'out' ? 'Out' : 'Low';
    const shops = count ?? 0;

    return (
        <StatusPill tone={row.status === 'low' ? 'warning' : 'danger'}>
            {label} in {number(shops)} {shops === 1 ? 'shop' : 'shops'}
        </StatusPill>
    );
}
const dash = <span className="text-muted-foreground">—</span>;

/** Stock on hand (module 5.1): one shop's lines, or every shop added up per product. Read only: the tills own stock. */
export default function StockIndex({ filters, summary, stock, options, hasStock }: StockIndexProps) {
    const { update, loading } = useTableQuery({ only: ONLY });
    const everyShop = filters.shop === 'all' || filters.shop === null;
    const shopParam = filters.shopLocked ? null : filters.shop;
    const change = (params: TableParams) => update(params);
    const tab = (status: string | undefined) => route('app.stock.index', withParams({ status }));
    const open = (row: StockRow) =>
        route('app.stock.products.show', { product: row.productId, ...(row.shopId && !filters.shopLocked ? { shop: row.shopId } : {}) });

    const columns = useMemo<ColumnDef<StockRow>[]>(
        () => [
            {
                id: 'product',
                header: 'Product',
                meta: { mobile: 'title' },
                cell: ({ row }) => (
                    <div className="grid max-w-72 leading-5">
                        <Link href={open(row.original)} className="truncate font-medium hover:underline" onClick={(e) => e.stopPropagation()}>
                            {row.original.name}
                        </Link>
                        <span className="text-muted-foreground truncate text-xs">
                            {[row.original.sku, row.original.department].filter(Boolean).join(' · ') || 'No SKU'}
                        </span>
                    </div>
                ),
            },
            {
                id: 'status',
                header: 'Status',
                meta: { mobile: 'field' },
                cell: ({ row }) => <RowStatus row={row.original} everyShop={everyShop} />,
            },
            ...(everyShop
                ? [
                      {
                          id: 'shops',
                          header: 'Shops',
                          meta: { mobile: 'field' as const },
                          cell: ({ row }: { row: { original: StockRow } }) => (
                              <span className="text-sm tabular-nums">
                                  {number(row.original.shops ?? 0)} {row.original.shops === 1 ? 'shop' : 'shops'}
                              </span>
                          ),
                      },
                  ]
                : [
                      {
                          id: 'lowAt',
                          header: 'Low at',
                          meta: { align: 'right' as const, mobile: 'hidden' as const },
                          cell: ({ row }: { row: { original: StockRow } }) => (
                              <Qty value={row.original.lowAt ?? '0'} className="text-muted-foreground" />
                          ),
                      },
                      {
                          id: 'available',
                          header: 'Available',
                          meta: { align: 'right' as const, mobile: 'hidden' as const },
                          cell: ({ row }: { row: { original: StockRow } }) => <Qty value={row.original.available ?? '0'} />,
                      },
                  ]),
            {
                id: 'onHand',
                header: 'On hand',
                meta: { align: 'right', mobile: 'aside' },
                cell: ({ row }) => <Qty value={row.original.onHand} className="font-medium" />,
            },
            {
                id: 'cost',
                header: 'Cost',
                meta: { align: 'right', mobile: 'hidden' },
                cell: ({ row }) => (row.original.cost ? <span className="tabular-nums">{money(row.original.cost)}</span> : dash),
            },
            {
                id: 'value',
                header: 'Value at cost',
                meta: { align: 'right', mobile: 'field' },
                cell: ({ row }) => (row.original.value ? <span className="tabular-nums">{money(row.original.value)}</span> : dash),
            },
        ],
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [everyShop, filters.shopLocked],
    );

    return (
        <AppLayout>
            <Head title="Stock" />

            <PageHeader
                title="Stock"
                description="What your tills say is on the shelf, with its value at cost. Stock is counted and moved on the tills, so it is read only here."
                tabs={<StockTabs active="index" shop={shopParam} />}
            />

            <StatGrid columns={4}>
                <StatCard
                    label={everyShop ? 'Products in stock lines' : 'Stock lines'}
                    value={number(everyShop ? summary.products : summary.lines)}
                    hint={`${number(Number(summary.units))} units on hand`}
                    icon={Boxes}
                />
                <StatCard
                    label="Value at cost"
                    value={money(summary.value)}
                    hint={
                        summary.costed < summary.lines ? `${number(summary.lines - summary.costed)} lines have no cost price` : 'On hand × cost price'
                    }
                    icon={PoundSterling}
                    tone="success"
                />
                <StatCard
                    label="Low stock"
                    value={number(summary.low)}
                    hint="At or below the low-stock point"
                    icon={AlertTriangle}
                    tone="warning"
                    href={tab('low')}
                />
                <StatCard
                    label="Out of stock"
                    value={number(summary.out)}
                    hint={summary.negative > 0 ? `${number(summary.negative)} below zero` : 'None or less on hand'}
                    icon={PackageX}
                    tone="danger"
                    href={tab('out')}
                />
            </StatGrid>

            <PageTabs
                label="Stock status"
                tabs={[
                    { label: 'All', href: tab(undefined), active: filters.status === null },
                    { label: 'Low', href: tab('low'), active: filters.status === 'low', count: summary.low },
                    { label: 'Out of stock', href: tab('out'), active: filters.status === 'out', count: summary.out },
                    { label: 'Negative', href: tab('negative'), active: filters.status === 'negative', count: summary.negative },
                ]}
            />

            <DataTable
                columns={columns}
                data={stock.data}
                meta={{ ...stock.meta, search: filters.search }}
                onChange={change}
                searchPlaceholder="Name, SKU or barcode"
                filters={<StockFilters filters={filters} options={options} update={update} show={{ department: true, supplier: true }} />}
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(open(row))}
                loading={loading}
                empty={
                    <EmptyState
                        icon={Boxes}
                        title={hasStock ? 'Nothing matches' : 'No stock from your tills yet'}
                        body={
                            hasStock
                                ? 'No stock-tracked product matches this search and these filters.'
                                : 'Stock levels appear here once a till with stock-tracked products has synced.'
                        }
                    />
                }
            />
        </AppLayout>
    );
}

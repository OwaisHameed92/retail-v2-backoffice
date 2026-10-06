import { changedAtLabel, difference, formatDateTime, pounds } from '@/components/app/pricing/format';
import { PricingTabs } from '@/components/app/pricing/pricing-tabs';
import { type PriceIndexProps, type PriceListRow } from '@/components/app/pricing/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { MoneyIcon } from '@/components/shared/money-icon';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/country';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { CalendarClock, Info, Package, Store, Tags } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['products', 'filters', 'counts'];

export default function PricesIndex({ products, shops, filters, departments, counts, restrictedShop }: PriceIndexProps) {
    const { update } = useTableQuery({ only: ONLY });
    const filtered = Boolean(products.meta.search) || filters.filter !== 'all' || filters.department !== null;

    const columns = useMemo<ColumnDef<PriceListRow>[]>(
        () => [
            {
                id: 'name',
                header: 'Product',
                enableSorting: true,
                cell: ({ row }) => (
                    <EntityCell
                        name={row.original.name}
                        subline={row.original.sku ?? undefined}
                        monoSubline
                        shape="square"
                        icon={Package}
                        className="max-w-72"
                    />
                ),
            },
            {
                id: 'sell_price',
                header: () => <span title="Product.sellPrice: what every shop charges unless it has its own price">Business price</span>,
                enableSorting: true,
                cell: ({ row }) => <span className="font-medium tabular-nums">{pounds(row.original.sellPrice)}</span>,
                meta: { align: 'right' },
            },
            ...shops.map<ColumnDef<PriceListRow>>((shop) => ({
                id: `shop-${shop.id}`,
                header: shop.name,
                cell: ({ row }) => {
                    const cell = row.original.shopPrices[shop.id];
                    if (!cell) {
                        return <span className="text-muted-foreground text-sm">Business price</span>;
                    }
                    const diff = difference(cell.price, row.original.sellPrice);
                    return (
                        <div
                            className="grid leading-5"
                            title={`Set by ${changedAtLabel(cell.changedAt).toLowerCase()}${cell.validTo ? `, until ${formatDateTime(cell.validTo)}` : ''}`}
                        >
                            <span className="font-medium tabular-nums">{pounds(cell.price)}</span>
                            <span className="text-muted-foreground text-xs">
                                {diff ?? 'Same'} · {changedAtLabel(cell.changedAt)}
                            </span>
                        </div>
                    );
                },
            })),
            {
                id: 'scheduled',
                header: 'Coming up',
                cell: ({ row }) =>
                    row.original.scheduled > 0 ? (
                        <Badge variant="secondary">
                            <CalendarClock className="size-3" aria-hidden />
                            {row.original.scheduled} scheduled
                        </Badge>
                    ) : null,
            },
        ],
        [shops],
    );

    return (
        <AppLayout>
            <Head title="Prices" />

            <PageHeader
                title="Prices"
                description="What each shop charges. A shop's own price beats the business price, and only that shop's tills get it."
                tabs={<PricingTabs current="prices" />}
            />

            {restrictedShop !== null && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>
                        You see your own shop. You can set its prices; the business price is shared by every shop, so it is read-only here.
                    </AlertDescription>
                </Alert>
            )}

            <StatGrid columns={4}>
                <StatCard label="Products on sale" value={formatNumber(counts.products)} icon={Package} tone="neutral" />
                <StatCard label="With a shop price" value={formatNumber(counts.withOwnPrice)} hint="Live now" icon={Store} tone="primary" />
                <StatCard label="Shop prices live" value={formatNumber(counts.livePrices)} icon={MoneyIcon} tone="success" />
                <StatCard label="Scheduled" value={formatNumber(counts.scheduled)} hint="Start later" icon={CalendarClock} tone="neutral" />
            </StatGrid>

            <DataTable
                columns={columns}
                data={products.data}
                meta={products.meta}
                only={ONLY}
                searchPlaceholder="Search by name, code or barcode"
                filters={
                    <>
                        <FilterSelect
                            value={filters.filter === 'own' ? 'own' : null}
                            onChange={(filter) => update({ filter, page: 1 })}
                            all="All products"
                            options={[{ value: 'own', label: 'With a shop price' }]}
                            label="Filter by shop prices"
                        />
                        <FilterSelect
                            value={filters.department}
                            onChange={(department) => update({ department, page: 1 })}
                            all="Any department"
                            options={departments}
                            label="Filter by department"
                        />
                    </>
                }
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.prices.show', row.id))}
                empty={
                    filtered ? undefined : (
                        <EmptyState icon={Tags} title="No products yet" body="Add products first; then you can give any shop its own price here." />
                    )
                }
            />
        </AppLayout>
    );
}

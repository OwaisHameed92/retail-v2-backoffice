import { formatDay, number, qty, signedMoney, StockTabs, withParams } from '@/components/app/stock/format';
import { movementColumns } from '@/components/app/stock/movement-columns';
import { StockFilters } from '@/components/app/stock/stock-filters';
import { type MovementsProps } from '@/components/app/stock/types';
import { DataTable, type TableParams, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeftRight, ChevronLeft, ChevronRight, X } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['movements', 'summary', 'filters', 'product'];

/** The tills' stock ledger (module 5.1): every stock change, newest first, paged without counting. Read only. */
export default function StockMovements({ movements, summary, product, typeOptions, filters, options, canViewSales }: MovementsProps) {
    const { update, loading } = useTableQuery({ only: ONLY });
    const change = (params: TableParams) => {
        const { page, search, ...rest } = params;
        void page;
        void search;
        update({ ...rest, after: undefined, before: undefined });
    };
    const columns = useMemo(() => movementColumns({ product: product === null, canViewSales }), [product, canViewSales]);
    const range = filters.from === filters.to ? formatDay(filters.from) : `${formatDay(filters.from)} – ${formatDay(filters.to)}`;

    const pager = (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-muted-foreground text-sm">Newest first · {range}</p>
            <div className="flex items-center gap-2">
                <Select
                    value={String(movements.perPage)}
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
                    disabled={!movements.newer || loading}
                    onClick={() => update({ before: movements.newer ?? undefined, after: undefined })}
                >
                    <ChevronLeft />
                    Newer
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!movements.older || loading}
                    onClick={() => update({ after: movements.older ?? undefined, before: undefined })}
                >
                    Older
                    <ChevronRight />
                </Button>
            </div>
        </div>
    );

    return (
        <AppLayout>
            <Head title={product ? `Movements · ${product.name}` : 'Stock movements'} />

            <PageHeader
                title="Stock movements"
                description="Every change to stock the tills have recorded: sales, goods in, adjustments, transfers, wastage and stock takes."
                tabs={<StockTabs active="movements" shop={filters.shopLocked ? null : filters.shop} />}
            />

            {product && (
                <div className="bg-muted/50 flex flex-wrap items-center justify-between gap-2 rounded-lg border px-4 py-2.5 text-sm">
                    <span>
                        Showing movements of{' '}
                        <Link href={route('app.stock.products.show', product.id)} className="font-medium hover:underline">
                            {product.name}
                        </Link>
                        {product.sku && <span className="text-muted-foreground"> · {product.sku}</span>}
                    </span>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={route('app.stock.movements', withParams({ product: undefined }))}>
                            <X />
                            Every product
                        </Link>
                    </Button>
                </div>
            )}

            {summary.length > 0 && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {summary.map((s) => (
                        <button
                            key={s.group}
                            type="button"
                            onClick={() => update({ type: filters.type === s.group ? undefined : s.group, after: undefined, before: undefined })}
                            className={`bg-card hover:border-primary/50 focus-visible:ring-ring rounded-lg border p-3 text-left transition focus-visible:ring-2 focus-visible:outline-none ${filters.type === s.group ? 'border-primary ring-primary/20 ring-2' : ''}`}
                            aria-pressed={filters.type === s.group}
                        >
                            <p className="text-muted-foreground text-xs">{s.label}</p>
                            <p className="mt-1 text-lg font-semibold tabular-nums">{Number(s.qty) > 0 ? `+${qty(s.qty)}` : qty(s.qty)}</p>
                            <p className="text-muted-foreground text-xs tabular-nums">
                                {number(s.count)} {s.count === 1 ? 'movement' : 'movements'} · {signedMoney(s.value)}
                            </p>
                        </button>
                    ))}
                </div>
            )}

            <DataTable
                columns={columns}
                data={movements.data}
                meta={{ page: 1, perPage: movements.perPage, total: movements.data.length }}
                onChange={change}
                searchable={false}
                filters={<StockFilters filters={filters} options={options} update={update} show={{ dates: true, types: typeOptions }} />}
                getRowId={(row) => row.id}
                loading={loading}
                footer={pager}
                empty={
                    <EmptyState
                        icon={ArrowLeftRight}
                        title="No movements"
                        body="Nothing matches these days and filters. Movements appear here once a till has synced them."
                    />
                }
            />
        </AppLayout>
    );
}

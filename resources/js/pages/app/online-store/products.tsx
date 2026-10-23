import { OnlineStoreTabs } from '@/components/app/online-store/online-store-tabs';
import { productColumns } from '@/components/app/online-store/product-columns';
import { ProductDialog } from '@/components/app/online-store/product-dialog';
import { PRODUCTS_ONLY, type OnlineProductRow, type ProductsProps, type ProductStatusFilter } from '@/components/app/online-store/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/country';
import { Head, router } from '@inertiajs/react';
import { Eye, EyeOff, Info, Package, PackageCheck, PackageX, Store, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const STATUS_LABELS: Record<ProductStatusFilter, string> = {
    all: 'All products',
    shown: 'Shown online',
    notShown: 'Not shown',
    hidden: 'Hidden by you',
};

function Filters({ shops, filters, departments }: Pick<ProductsProps, 'shops' | 'filters' | 'departments'>) {
    const { update } = useTableQuery({ only: PRODUCTS_ONLY });
    const filtered = filters.status !== 'all' || filters.department !== null;

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-1 sm:flex-row sm:flex-wrap sm:items-center">
            {shops.length > 1 && (
                <Select value={filters.shop ?? ''} onValueChange={(v) => update({ shop: v, page: 1 })}>
                    <SelectTrigger className="h-9 w-full sm:w-52" aria-label="Shop whose prices and rules are shown">
                        <Store className="text-muted-foreground" />
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {shops.map((s) => (
                            <SelectItem key={s.id} value={s.id}>
                                {s.name}
                                {s.collects ? '' : ' (no collection)'}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}
            <Select value={filters.status} onValueChange={(v) => update({ status: v === 'all' ? undefined : v, page: 1 })}>
                <SelectTrigger className="h-9 w-full sm:w-44" aria-label="Filter by online status">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {(Object.keys(STATUS_LABELS) as ProductStatusFilter[]).map((key) => (
                        <SelectItem key={key} value={key}>
                            {STATUS_LABELS[key]}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {departments.length > 0 && (
                <Select value={filters.department ?? 'all'} onValueChange={(v) => update({ department: v === 'all' ? undefined : v, page: 1 })}>
                    <SelectTrigger className="h-9 w-full sm:w-48" aria-label="Filter by department">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All departments</SelectItem>
                        {departments.map((d) => (
                            <SelectItem key={d.id} value={d.id}>
                                {d.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}
            {filtered && (
                <Button
                    type="button"
                    variant="ghost"
                    className="text-muted-foreground h-9"
                    onClick={() => update({ status: undefined, department: undefined, page: 1 })}
                >
                    <X />
                    Clear filters
                </Button>
            )}
        </div>
    );
}

/** OS-3: what the online store shows, why a product is not shown, and each product's online photo and description. */
export default function OnlineStoreProducts(props: ProductsProps) {
    const { products, counts, canEdit, shops, filters } = props;
    const [selected, setSelected] = useState<string[]>([]);
    const [editing, setEditing] = useState<OnlineProductRow | null>(null);
    const [sending, setSending] = useState(false);
    const shop = shops.find((s) => s.id === filters.shop);

    // A new page or filter starts with nothing selected.
    const pageKey = products.data.map((r) => r.id).join();
    useEffect(() => setSelected((ids) => ids.filter((id) => pageKey.includes(id))), [pageKey]);

    const setVisibility = (ids: string[], hidden: boolean) => {
        setSending(true);
        router.post(
            route('app.online-store.products.visibility'),
            { ids, hidden },
            { preserveScroll: true, preserveState: true, onSuccess: () => setSelected([]), onFinish: () => setSending(false) },
        );
    };

    const columns = useMemo(
        () => productColumns({ canEdit, selected, setSelected, rows: products.data, onEdit: setEditing, onVisibility: setVisibility }),
        [canEdit, selected, products.data],
    );
    const filtered = Boolean(products.meta.search) || filters.status !== 'all' || filters.department !== null;

    return (
        <AppLayout>
            <Head title="Online products" />
            <PageHeader
                title="Online store"
                description="Products, prices and stock come from your tills. Hide what you do not want online and add photos and short descriptions."
                tabs={<OnlineStoreTabs active="products" />}
            />

            <StatGrid columns={3}>
                <StatCard
                    label="Shown online"
                    value={formatNumber(counts.shown)}
                    hint={shop ? `At ${shop.name}` : undefined}
                    icon={PackageCheck}
                    tone="success"
                />
                <StatCard
                    label="Not shown"
                    value={formatNumber(Math.max(0, counts.total - counts.shown))}
                    hint="By the rules or hidden by you"
                    icon={PackageX}
                    tone="warning"
                />
                <StatCard
                    label="Hidden by you"
                    value={formatNumber(counts.hidden)}
                    hint={`Of ${formatNumber(counts.total)} active products`}
                    icon={EyeOff}
                    tone="neutral"
                />
            </StatGrid>

            <Alert variant="info">
                <Info />
                <AlertDescription>
                    Age-restricted products, alcohol, tobacco, lottery, knives, vapes and pharmacy or prescription medicines are never sold online. A
                    product also needs a price at the shop. Stock and prices follow your tills.
                </AlertDescription>
            </Alert>

            <DataTable
                columns={columns}
                data={products.data}
                meta={products.meta}
                only={PRODUCTS_ONLY}
                searchPlaceholder="Search name, code or barcode"
                filters={<Filters shops={shops} filters={filters} departments={props.departments} />}
                getRowId={(row) => row.id}
                onRowClick={canEdit ? (row) => setEditing(row) : undefined}
                empty={
                    filtered ? undefined : (
                        <EmptyState
                            icon={Package}
                            title={shops.length === 0 ? 'No open shops yet' : 'No products yet'}
                            body={
                                shops.length === 0
                                    ? 'The store shows products per shop. Products appear here once a shop is set up.'
                                    : 'Products added on your tills or in Products appear here after they sync.'
                            }
                        />
                    )
                }
            />

            {canEdit && selected.length > 0 && (
                <StickyFormBar message={`${selected.length} ${selected.length === 1 ? 'product' : 'products'} selected`}>
                    <Button variant="ghost" disabled={sending} onClick={() => setSelected([])}>
                        Clear selection
                    </Button>
                    <Button variant="outline" disabled={sending} onClick={() => setVisibility(selected, false)}>
                        <Eye />
                        Show online
                    </Button>
                    <Button variant="outline" disabled={sending} onClick={() => setVisibility(selected, true)}>
                        <EyeOff />
                        Hide online
                    </Button>
                </StickyFormBar>
            )}

            {canEdit && <ProductDialog product={editing} onClose={() => setEditing(null)} descriptionMax={props.descriptionMax} />}
        </AppLayout>
    );
}

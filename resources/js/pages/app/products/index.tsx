import { productColumns } from '@/components/app/products/product-columns';
import { PRODUCT_INDEX_ONLY, ProductFilters } from '@/components/app/products/product-filters';
import { type ProductIndexProps, type ProductRow } from '@/components/app/products/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Archive, BookOpenCheck, FolderTree, Package, Plus, Sparkles, Tags, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';

const number = new Intl.NumberFormat('en-GB');

export default function Products(props: ProductIndexProps) {
    const { products, filters, counts, canManage } = props;
    const [archiving, setArchiving] = useState<ProductRow | null>(null);
    const columns = useMemo(() => productColumns(canManage, setArchiving), [canManage]);
    const filtered = Boolean(products.meta.search || filters.department || filters.category) || filters.status !== 'active';

    return (
        <AppLayout>
            <Head title="Products" />

            <PageHeader
                title="Products"
                description="Your catalogue for every shop. Changes reach your tills at their next sync."
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link href={route('app.products.groups')}>
                                <FolderTree />
                                Departments
                            </Link>
                        </Button>
                        {canManage && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link href={route('app.products.imports.index')}>
                                        <Upload />
                                        Import CSV
                                    </Link>
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={route('app.products.catalogue.index')}>
                                        <BookOpenCheck />
                                        Add from catalogue
                                    </Link>
                                </Button>
                                <Button asChild>
                                    <Link href={route('app.products.create')}>
                                        <Plus />
                                        Add product
                                    </Link>
                                </Button>
                            </>
                        )}
                    </>
                }
            />

            <StatGrid>
                <StatCard label="Active products" value={number.format(counts.active)} hint="On sale at your tills" icon={Package} tone="primary" />
                <StatCard label="Archived" value={number.format(counts.archived)} hint="Kept for history, not sold" icon={Archive} tone="neutral" />
                <StatCard
                    label="Departments"
                    value={number.format(counts.departments)}
                    hint="Top level of your catalogue"
                    icon={FolderTree}
                    tone="neutral"
                    href={route('app.products.groups')}
                />
                <StatCard label="Categories" value={number.format(counts.categories)} hint="Including sub-categories" icon={Tags} tone="neutral" />
            </StatGrid>

            <DataTable
                columns={columns}
                data={products.data}
                meta={products.meta}
                only={PRODUCT_INDEX_ONLY}
                searchPlaceholder="Search by name, code or scan a barcode"
                filters={<ProductFilters filters={filters} options={props.options} counts={counts} />}
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.products.show', row.id))}
                empty={
                    filtered ? undefined : (
                        <EmptyState
                            icon={Package}
                            title="No products yet"
                            body="Start with a ready-made range for your kind of shop, pick products from the SSPOS catalogue, or import a spreadsheet. Products added on your tills appear here after they sync."
                            action={
                                canManage ? (
                                    <div className="flex flex-wrap justify-center gap-2">
                                        <Button asChild>
                                            <Link href={route('app.products.starter')}>
                                                <Sparkles />
                                                Starter pack
                                            </Link>
                                        </Button>
                                        <Button variant="outline" asChild>
                                            <Link href={route('app.products.catalogue.index')}>
                                                <BookOpenCheck />
                                                Add from catalogue
                                            </Link>
                                        </Button>
                                        <Button variant="outline" asChild>
                                            <Link href={route('app.products.imports.index')}>
                                                <Upload />
                                                Import CSV
                                            </Link>
                                        </Button>
                                        <Button variant="outline" asChild>
                                            <Link href={route('app.products.create')}>
                                                <Plus />
                                                Add product
                                            </Link>
                                        </Button>
                                    </div>
                                ) : undefined
                            }
                        />
                    )
                }
            />

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title={`Archive ${archiving?.name ?? 'this product'}?`}
                description="Your tills stop offering it at their next sync. Its sales history stays, and you can restore it at any time."
                confirmLabel="Archive product"
                destructive
                onConfirm={() =>
                    new Promise((resolve) =>
                        router.post(
                            route('app.products.archive', archiving?.id ?? ''),
                            {},
                            {
                                preserveScroll: true,
                                onFinish: () => {
                                    setArchiving(null);
                                    resolve(null);
                                },
                            },
                        ),
                    )
                }
            />
        </AppLayout>
    );
}

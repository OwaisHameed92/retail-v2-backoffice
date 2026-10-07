import { CatalogueTabs } from '@/components/admin/catalogue/catalogue-tabs';
import { SOURCE_TONES, type MasterIndexProps, type MasterRow } from '@/components/admin/catalogue/types';
import { formatDateTime } from '@/components/admin/format';
import { formatMoney } from '@/components/app/products/fields';
import { FilterSelect } from '@/components/app/setup/fields';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { formatNumber, taxName } from '@/lib/country';
import { hasModule } from '@/lib/country-modules';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { BookOpenCheck, Copy, Inbox, Package, Plus, Sparkles, Upload } from 'lucide-react';
import { useState } from 'react';

const ONLY = ['products', 'filters', 'counts', 'departments'];

const columns: ColumnDef<MasterRow>[] = [
    {
        id: 'name',
        header: 'Product',
        enableSorting: true,
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <EntityCell
                name={row.original.name}
                subline={<span className="font-mono tabular-nums">{[row.original.barcode, row.original.size].filter(Boolean).join(' · ')}</span>}
                icon={Package}
                shape="square"
                className="max-w-96"
            />
        ),
    },
    {
        id: 'group',
        header: 'Department',
        cell: ({ row }) => (
            <div className="min-w-0 leading-tight">
                <p className="truncate">{row.original.department ?? '—'}</p>
                {row.original.category && <p className="text-muted-foreground truncate text-xs">{row.original.category}</p>}
            </div>
        ),
    },
    {
        id: 'rrp',
        header: 'RRP',
        enableSorting: true,
        meta: { align: 'right', mobile: 'aside' },
        cell: ({ row }) => (
            <div className="text-right leading-tight tabular-nums">
                <p className="font-medium">{row.original.rrp ? formatMoney(row.original.rrp) : '—'}</p>
                {row.original.vatRate && (
                    <p className="text-muted-foreground text-xs">
                        {taxName()} {row.original.vatRate}
                    </p>
                )}
            </div>
        ),
    },
    {
        id: 'flags',
        header: 'Checks',
        cell: ({ row }) => (
            <div className="flex flex-wrap gap-1">
                {row.original.ageLabel && <Badge variant="warning">{row.original.ageLabel}</Badge>}
                {row.original.inStarterPacks && <Badge variant="outline">Starter pack</Badge>}
            </div>
        ),
    },
    {
        id: 'updated_at',
        header: 'Source',
        enableSorting: true,
        cell: ({ row }) => (
            <div className="leading-tight">
                <StatusBadge status={row.original.source} label={row.original.sourceLabel} tone={SOURCE_TONES[row.original.source]} />
                <p className="text-muted-foreground mt-1 text-xs tabular-nums">{formatDateTime(row.original.updatedAt, '—')}</p>
            </div>
        ),
    },
];

/** The SSPOS master catalogue: what new businesses pick from and what barcode lookup finds. */
export default function MasterCatalogue({ products, filters, sources, departments, counts }: MasterIndexProps) {
    const { update } = useTableQuery({ only: ONLY });
    const [loading, setLoading] = useState(false);
    const filtered = Boolean(products.meta.search || filters.source || filters.department || filters.duplicates);
    const loadStarter = () =>
        new Promise((resolve) =>
            router.post(
                route('admin.catalogue.starter'),
                {},
                { preserveScroll: true, onStart: () => setLoading(true), onFinish: () => resolve(setLoading(false)) },
            ),
        );

    // Pakistan plan P10: the starter set is UK products, not offered where the country profile hides it.
    const starterButton = hasModule('ukStarterSet') ? (
        <ConfirmDialog
            trigger={
                <Button variant="outline">
                    <Sparkles />
                    Load starter set
                </Button>
            }
            title="Load the starter set?"
            description="Adds about 600 UK convenience products from the demo catalogue, marked “Starter set”. Most of their barcodes are generated, so replace them with a licensed file before relying on lookups. Products you edited or imported are left alone."
            confirmLabel="Load starter set"
            processing={loading}
            onConfirm={loadStarter}
        />
    ) : null;

    return (
        <AdminLayout>
            <Head title="Catalogue" />
            <PageHeader
                title="Catalogue"
                icon={BookOpenCheck}
                description="The SSPOS master catalogue. Businesses add products from it and the product form looks barcodes up in it. It is never sent to tills directly."
                actions={
                    <>
                        {starterButton}
                        <Button variant="outline" asChild>
                            <Link href={route('admin.catalogue.imports.index')}>
                                <Upload />
                                Load CSV
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={route('admin.catalogue.create')}>
                                <Plus />
                                Add product
                            </Link>
                        </Button>
                    </>
                }
                tabs={<CatalogueTabs pending={counts.pending} />}
            />

            <StatGrid>
                <StatCard
                    label="Products"
                    value={formatNumber(counts.total)}
                    hint={`${formatNumber(departments.length)} departments`}
                    icon={Package}
                    tone="primary"
                />
                <StatCard
                    label="Starter set"
                    value={formatNumber(counts.bySource.starter ?? 0)}
                    hint="Demo lines: replace with a licensed file"
                    icon={Sparkles}
                    tone={(counts.bySource.starter ?? 0) > 0 ? 'warning' : 'neutral'}
                />
                <StatCard
                    label="Barcodes to review"
                    value={formatNumber(counts.pending)}
                    hint="Sold on tills, not in the catalogue"
                    icon={Inbox}
                    tone={counts.pending > 0 ? 'primary' : 'neutral'}
                    href={route('admin.catalogue.contributions.index')}
                />
                <StatCard
                    label="Possible duplicates"
                    value={formatNumber(counts.duplicates)}
                    hint="Names on more than one barcode"
                    icon={Copy}
                    tone={counts.duplicates > 0 ? 'warning' : 'neutral'}
                    href={route('admin.catalogue.index', { duplicates: 1 })}
                />
            </StatGrid>

            <DataTable
                columns={columns}
                data={products.data}
                meta={products.meta}
                only={ONLY}
                searchPlaceholder="Search name or brand, or scan a barcode"
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('admin.catalogue.edit', row.id))}
                filters={
                    <>
                        <FilterSelect
                            value={filters.source}
                            onChange={(source) => update({ source, page: undefined })}
                            all="Every source"
                            options={sources}
                            label="Filter by source"
                        />
                        <FilterSelect
                            value={filters.department}
                            onChange={(department) => update({ department, page: undefined })}
                            all="Every department"
                            options={departments.map((d) => ({ value: d.value, label: `${d.label} (${formatNumber(d.count)})` }))}
                            label="Filter by department"
                            width="sm:w-56"
                        />
                        <FilterSelect
                            value={filters.duplicates ? '1' : null}
                            onChange={(value) => update({ duplicates: value ? 1 : undefined, page: undefined })}
                            all="All products"
                            options={[{ value: '1', label: 'Possible duplicates' }]}
                            label="Show possible duplicates"
                        />
                    </>
                }
                empty={
                    filtered ? undefined : (
                        <EmptyState
                            icon={BookOpenCheck}
                            title="The catalogue is empty"
                            body={
                                starterButton
                                    ? 'Load the starter set to get going, then load a licensed supplier file or add products by hand.'
                                    : 'Load a licensed supplier file or add products by hand to get going.'
                            }
                            action={starterButton ?? undefined}
                        />
                    )
                }
            />
        </AdminLayout>
    );
}

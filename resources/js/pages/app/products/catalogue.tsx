import { AddDialog } from '@/components/app/catalogue/add-dialog';
import { SharingCard } from '@/components/app/catalogue/sharing-card';
import { type CatalogueRow, type CatalogueSearchProps } from '@/components/app/catalogue/types';
import { formatMoney } from '@/components/app/products/fields';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { BookOpenCheck, Info, PackagePlus, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';

const ONLY = ['products', 'filters'];
const number = new Intl.NumberFormat('en-GB');

function columns(
    selected: Map<string, CatalogueRow>,
    toggle: (row: CatalogueRow, on: boolean) => void,
    pageRows: CatalogueRow[],
): ColumnDef<CatalogueRow>[] {
    const selectable = pageRows.filter((row) => !row.inCatalogue);
    const allOn = selectable.length > 0 && selectable.every((row) => selected.has(row.barcode));

    return [
        {
            id: 'select',
            header: () => (
                <Checkbox
                    aria-label="Select every product on this page"
                    checked={allOn}
                    disabled={selectable.length === 0}
                    onCheckedChange={(state) => selectable.forEach((row) => toggle(row, state === true))}
                />
            ),
            meta: { mobile: 'hidden' },
            cell: ({ row }) => (
                <Checkbox
                    aria-label={`Select ${row.original.name}`}
                    checked={row.original.inCatalogue || selected.has(row.original.barcode)}
                    disabled={row.original.inCatalogue}
                    onClick={(e) => e.stopPropagation()}
                    onCheckedChange={(state) => toggle(row.original, state === true)}
                />
            ),
        },
        {
            id: 'name',
            header: 'Product',
            enableSorting: true,
            meta: { mobile: 'title' },
            cell: ({ row }) => (
                <div className="min-w-0 leading-tight">
                    <p className="truncate font-medium">{row.original.name}</p>
                    <p className="text-muted-foreground font-mono text-xs tabular-nums">
                        {[row.original.barcode, row.original.size].filter(Boolean).join(' · ')}
                    </p>
                </div>
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
                    {row.original.vatRate && <p className="text-muted-foreground text-xs">VAT {row.original.vatRate}</p>}
                </div>
            ),
        },
        {
            id: 'status',
            header: '',
            cell: ({ row }) => (
                <div className="flex flex-wrap justify-end gap-1">
                    {row.original.ageLabel && <Badge variant="warning">{row.original.ageLabel}</Badge>}
                    {row.original.inCatalogue && <Badge variant="success">In your catalogue</Badge>}
                </div>
            ),
        },
    ];
}

/** Pick products from the SSPOS catalogue instead of keying them in; they reach every till at its next sync. */
export default function AddFromCatalogue(props: CatalogueSearchProps) {
    const { products, filters, departments, yourDepartments, hasVatRates, priceRule, sharing } = props;
    const { update } = useTableQuery({ only: ONLY });
    const [selected, setSelected] = useState<Map<string, CatalogueRow>>(new Map());
    const [reviewing, setReviewing] = useState(false);

    const toggle = (row: CatalogueRow, on: boolean) =>
        setSelected((current) => {
            const next = new Map(current);
            if (on && !row.inCatalogue) {
                next.set(row.barcode, row);
            } else {
                next.delete(row.barcode);
            }

            return next;
        });
    const cols = useMemo(() => columns(selected, toggle, products.data), [selected, products.data]);

    return (
        <AppLayout>
            <Head title="Add from catalogue" />
            <PageHeader
                title="Add from catalogue"
                icon={BookOpenCheck}
                description="Search thousands of UK products by name or barcode, tick the ones you sell and add them in one go. Products you already have are marked."
                back={{ href: route('app.products.index'), label: 'Products' }}
                actions={
                    <Button variant="outline" asChild>
                        <Link href={route('app.products.starter')}>
                            <Sparkles />
                            Starter pack
                        </Link>
                    </Button>
                }
            />

            {!hasVatRates && (
                <Alert variant="warning">
                    <Info />
                    <AlertTitle>No VAT rates yet</AlertTitle>
                    <AlertDescription>
                        Your VAT rates arrive from your till at its first sync. Connect a till first, then add products here.
                    </AlertDescription>
                </Alert>
            )}

            <DataTable
                columns={cols}
                data={products.data}
                meta={products.meta}
                only={ONLY}
                searchPlaceholder="Search by name or brand, or scan a barcode"
                getRowId={(row) => row.id}
                onRowClick={(row) => !row.inCatalogue && toggle(row, !selected.has(row.barcode))}
                filters={
                    <FilterSelect
                        value={filters.department}
                        onChange={(department) => update({ department, page: undefined })}
                        all="Every department"
                        options={departments.map((d) => ({ value: d.value, label: `${d.label} (${number.format(d.count)})` }))}
                        label="Filter by department"
                        width="sm:w-60"
                    />
                }
                empty={
                    products.meta.search || filters.department ? undefined : (
                        <EmptyState
                            icon={BookOpenCheck}
                            title="The catalogue is being prepared"
                            body="SSPOS is loading products into the catalogue. Please check again soon."
                        />
                    )
                }
            />

            {selected.size > 0 && (
                <StickyFormBar message={`${number.format(selected.size)} selected. Your choice is kept while you search.`}>
                    <Button variant="outline" onClick={() => setSelected(new Map())}>
                        Clear
                    </Button>
                    <Button onClick={() => setReviewing(true)} disabled={!hasVatRates}>
                        <PackagePlus />
                        Review and add
                    </Button>
                </StickyFormBar>
            )}

            <SharingCard sharing={sharing} />

            {reviewing && (
                <AddDialog
                    rows={[...selected.values()]}
                    yours={yourDepartments}
                    initialRule={{ price_rule: priceRule.mode, margin: String(priceRule.margin), end_in_9: true }}
                    onRemove={(barcode) => setSelected((current) => new Map([...current].filter(([key]) => key !== barcode)))}
                    onClose={() => setReviewing(false)}
                    onAdded={() => {
                        setReviewing(false);
                        setSelected(new Map());
                    }}
                />
            )}
        </AppLayout>
    );
}

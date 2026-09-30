import { EntityCell } from '@/components/shared/entity-cell';
import { RowActions } from '@/components/shared/row-actions';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Archive, ArchiveRestore, Eye, Package, Pencil } from 'lucide-react';
import { formatMoney } from './fields';
import { type ProductRow } from './types';

/** Products list. Column ids are the server sort keys; phones get cards (name, price, status). */
export function productColumns(canManage: boolean, onArchive: (row: ProductRow) => void): ColumnDef<ProductRow>[] {
    return [
        {
            id: 'name',
            header: 'Product',
            enableSorting: true,
            cell: ({ row }) => (
                <EntityCell
                    name={row.original.name}
                    subline={[row.original.barcode, row.original.sku].filter(Boolean).join(' · ') || 'No barcode'}
                    icon={Package}
                    className="max-w-96"
                />
            ),
        },
        {
            id: 'group',
            header: 'Department',
            meta: { label: 'Department' },
            cell: ({ row }) => (
                <div className="min-w-0 leading-tight">
                    <p className="truncate">{row.original.department ?? '—'}</p>
                    {row.original.category && <p className="text-muted-foreground truncate text-xs">{row.original.category}</p>}
                </div>
            ),
        },
        {
            id: 'sell_price',
            header: 'Price',
            enableSorting: true,
            meta: { align: 'right', mobile: 'aside' },
            cell: ({ row }) => (
                <div className="text-right leading-tight tabular-nums">
                    <p className="font-medium">{formatMoney(row.original.sellPrice)}</p>
                    {row.original.vat && <p className="text-muted-foreground text-xs">VAT {row.original.vat}</p>}
                </div>
            ),
        },
        {
            id: 'cost_price',
            header: 'Cost',
            enableSorting: true,
            meta: { align: 'right' },
            cell: ({ row }) => <span className="text-muted-foreground tabular-nums">{formatMoney(row.original.costPrice, 4)}</span>,
        },
        {
            id: 'status',
            header: 'Status',
            cell: ({ row }) => (
                <div className="flex flex-wrap items-center gap-1.5">
                    <StatusBadge status={row.original.isActive ? 'active' : 'archived'} />
                    {row.original.ageRestricted && <Badge variant="warning">Age check</Badge>}
                </div>
            ),
        },
        {
            id: 'actions',
            header: () => <span className="sr-only">Actions</span>,
            meta: { align: 'right', mobile: 'actions' },
            cell: ({ row }) => (
                <RowActions
                    label={`Actions for ${row.original.name}`}
                    actions={[
                        { label: canManage ? 'Edit' : 'View', icon: canManage ? Pencil : Eye, href: route('app.products.show', row.original.id) },
                        row.original.isActive
                            ? { label: 'Archive', icon: Archive, destructive: true, hidden: !canManage, onSelect: () => onArchive(row.original) }
                            : {
                                  label: 'Restore',
                                  icon: ArchiveRestore,
                                  hidden: !canManage,
                                  onSelect: () => router.post(route('app.products.restore', row.original.id), {}, { preserveScroll: true }),
                              },
                    ]}
                />
            ),
        },
    ];
}

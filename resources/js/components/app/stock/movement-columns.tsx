import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { Link } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { formatDateTime, Qty, signedMoney } from './format';
import { type MovementRow } from './types';

const GROUP_TONE: Record<string, StatusTone> = {
    sales: 'info',
    goodsIn: 'success',
    adjustments: 'neutral',
    transfers: 'violet',
    wastage: 'danger',
    stockTakes: 'warning',
};

const dash = <span className="text-muted-foreground">—</span>;

/** The movements table's columns; `product` = show the product column (every product), `canViewSales` links sales. */
export function movementColumns({
    product = true,
    canViewSales = false,
}: { product?: boolean; canViewSales?: boolean } = {}): ColumnDef<MovementRow>[] {
    return [
        {
            id: 'when',
            header: 'When',
            meta: { mobile: product ? 'field' : 'title' },
            cell: ({ row }) => (
                <div className="grid leading-5">
                    <span className="text-sm whitespace-nowrap">{formatDateTime(row.original.at)}</span>
                    <span className="text-muted-foreground text-xs">{row.original.shop}</span>
                </div>
            ),
        },
        ...(product
            ? [
                  {
                      id: 'product',
                      header: 'Product',
                      meta: { mobile: 'title' as const },
                      cell: ({ row }: { row: { original: MovementRow } }) => (
                          <div className="grid max-w-60 leading-5">
                              <Link
                                  href={route('app.stock.products.show', row.original.productId)}
                                  className="truncate font-medium hover:underline"
                                  onClick={(e) => e.stopPropagation()}
                              >
                                  {row.original.product}
                              </Link>
                              {row.original.sku && <span className="text-muted-foreground truncate text-xs">{row.original.sku}</span>}
                          </div>
                      ),
                  },
              ]
            : []),
        {
            id: 'kind',
            header: 'Kind',
            meta: { mobile: 'field' },
            cell: ({ row }) => <StatusPill tone={GROUP_TONE[row.original.group ?? ''] ?? 'neutral'}>{row.original.typeLabel}</StatusPill>,
        },
        {
            id: 'detail',
            header: 'Detail',
            meta: { mobile: 'hidden' },
            cell: ({ row }) => {
                const r = row.original;
                const bits = [r.reason, r.note, r.staff].filter(Boolean).join(' · ');
                const sale = canViewSales && r.refId && (r.refType === 'sale' || r.refType === 'Sale');

                return (
                    <div className="grid max-w-64 text-sm leading-5">
                        {sale ? (
                            <Link
                                href={route('app.sales.show', r.refId as string)}
                                className="text-primary hover:underline"
                                onClick={(e) => e.stopPropagation()}
                            >
                                View sale
                            </Link>
                        ) : null}
                        {bits ? <span className="text-muted-foreground truncate">{bits}</span> : !sale && dash}
                    </div>
                );
            },
        },
        {
            id: 'qty',
            header: 'Change',
            meta: { align: 'right', mobile: 'aside' },
            cell: ({ row }) => <Qty value={row.original.qty} signed className="font-medium" />,
        },
        {
            id: 'after',
            header: 'Stock after',
            meta: { align: 'right', mobile: 'hidden' },
            cell: ({ row }) => <Qty value={row.original.after} className="text-muted-foreground" />,
        },
        {
            id: 'value',
            header: 'Value',
            meta: { align: 'right', mobile: 'field' },
            cell: ({ row }) => (Number(row.original.unitCost) === 0 ? dash : <span className="tabular-nums">{signedMoney(row.original.value)}</span>),
        },
    ];
}

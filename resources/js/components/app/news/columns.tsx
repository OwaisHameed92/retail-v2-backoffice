import { RowActions, type RowAction } from '@/components/shared/row-actions';
import { StatusPill } from '@/components/shared/status-badge';
import { cn } from '@/lib/utils';
import { type ColumnDef } from '@tanstack/react-table';
import { Archive, ArchiveRestore, Pencil } from 'lucide-react';
import { copies, formatDateTime, formatDay, FREQUENCIES, money, NewsStatus, percent } from './format';
import { type NewsKind, type NewsRow } from './types';

type Column = ColumnDef<NewsRow>;

const dash = <span className="text-muted-foreground">—</span>;
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : null);

const status: Column = { id: 'status', header: 'Status', cell: ({ row }) => <NewsStatus status={row.original.status} /> };

const number = (id: string, header: string, key: string, tone?: (row: NewsRow) => string | undefined): Column => ({
    id,
    header,
    cell: ({ row }) => <span className={cn('tabular-nums', tone?.(row.original))}>{copies(row.original[key] as number)}</span>,
    meta: { align: 'right' },
});

const amount = (id: string, header: string, key: string, sortable = false): Column => ({
    id,
    header,
    enableSorting: sortable,
    cell: ({ row }) => <span className="tabular-nums">{money(row.original[key] as string | null)}</span>,
    meta: { align: 'right' },
});

const delivered = (showShop: boolean): Column => ({
    id: 'delivery_date',
    header: 'Delivered',
    enableSorting: true,
    cell: ({ row }) => (
        <div className="grid leading-5">
            <span className="font-medium">{formatDay(text(row.original.date))}</span>
            <span className="text-muted-foreground text-xs">
                {[row.original.supplier, showShop ? row.original.shop : null].filter(Boolean).join(' · ')}
            </span>
        </div>
    ),
    meta: { mobile: 'title' },
});

export interface TitleActions {
    onArchive: (row: NewsRow) => void;
    onRestore: (row: NewsRow) => void;
}

/** The list columns of each newspaper kind. Sortable ids are the server's sort keys. */
export function newsColumns(kind: NewsKind, showShop: boolean, manage: boolean, actions: TitleActions): Column[] {
    switch (kind) {
        case 'titles':
            return [
                {
                    id: 'name',
                    header: 'Title',
                    enableSorting: true,
                    cell: ({ row }) => (
                        <div className="grid max-w-72 leading-5">
                            <span className="truncate font-medium">{row.original.name as string}</span>
                            <span className="text-muted-foreground truncate text-xs">
                                {[row.original.publisher, FREQUENCIES[row.original.frequency as string]].filter(Boolean).join(' · ')}
                            </span>
                        </div>
                    ),
                    meta: { mobile: 'title' },
                },
                {
                    id: 'shop',
                    header: 'Sold at',
                    cell: ({ row }) =>
                        row.original.shopId ? <span>{row.original.shop ?? 'Unknown shop'}</span> : <StatusPill tone="info">Every shop</StatusPill>,
                },
                { id: 'supplier', header: 'Wholesaler', cell: ({ row }) => <span className="block max-w-48 truncate">{row.original.supplier}</span> },
                {
                    id: 'product',
                    header: 'Till product',
                    cell: ({ row }) =>
                        text(row.original.product) ? (
                            <div className="grid max-w-56 leading-5">
                                <span className="truncate">{row.original.product as string}</span>
                                <span className="text-muted-foreground truncate text-xs">
                                    {[text(row.original.barcode), text(row.original.vat)].filter(Boolean).join(' · ')}
                                </span>
                                {row.original.vatNotZero === true && <span className="text-warning-foreground text-xs">Not zero-rated</span>}
                            </div>
                        ) : (
                            <div className="grid justify-items-start gap-1 leading-5">
                                {text(row.original.barcode) && <span className="font-mono text-xs">{row.original.barcode as string}</span>}
                                <StatusPill tone="warning">No product: no VAT line on the till</StatusPill>
                            </div>
                        ),
                },
                amount('cover_price', 'Cover price', 'coverPrice', true),
                {
                    id: 'sold',
                    header: 'Sold (4 wks)',
                    cell: ({ row }) => {
                        const inQty = row.original.qtyIn as number;
                        const sold = row.original.qtySold as number;

                        return inQty > 0 ? (
                            <div className="grid text-right leading-5">
                                <span className="tabular-nums">
                                    {copies(sold)} of {copies(inQty)}
                                </span>
                                <span className="text-muted-foreground text-xs">{percent((100 * sold) / inQty)} sold</span>
                            </div>
                        ) : (
                            dash
                        );
                    },
                    meta: { align: 'right' },
                },
                status,
                ...(manage
                    ? [
                          {
                              id: 'actions',
                              header: '',
                              cell: ({ row }) => {
                                  const r = row.original;
                                  const list: RowAction[] = [
                                      { label: 'Edit', icon: Pencil, href: route('app.news.titles.edit', r.id) },
                                      r.status === 'active'
                                          ? { label: 'Archive', icon: Archive, onSelect: () => actions.onArchive(r), destructive: true }
                                          : { label: 'Put back on sale', icon: ArchiveRestore, onSelect: () => actions.onRestore(r) },
                                  ];

                                  return r.canEdit ? <RowActions label={`Actions for ${r.name as string}`} actions={list} /> : null;
                              },
                              meta: { align: 'right' },
                          } satisfies Column,
                      ]
                    : []),
            ];
        case 'deliveries':
            return [
                delivered(showShop),
                number('qtyIn', 'Copies in', 'qtyIn'),
                number('qtySold', 'Sold', 'qtySold'),
                number('qtyReturned', 'Returned', 'qtyReturned'),
                amount('cost', 'Cost', 'cost'),
                amount('credit', 'Return credit', 'credit'),
                status,
            ];
        case 'returns':
            return [
                delivered(showShop),
                number('qtyIn', 'Copies in', 'qtyIn'),
                number('qtyReturned', 'Returned', 'qtyReturned'),
                { id: 'rate', header: 'Return rate', cell: ({ row }) => percent(row.original.returnRate as number | null), meta: { align: 'right' } },
                amount('credit', 'Credit due', 'gross'),
                {
                    id: 'credit_posted_at',
                    header: 'Credited',
                    enableSorting: true,
                    cell: ({ row }) => (text(row.original.creditPostedAt) ? formatDateTime(text(row.original.creditPostedAt)) : dash),
                },
                status,
            ];
        case 'vouchers':
            return [
                {
                    id: 'voucher_code',
                    header: 'Voucher',
                    enableSorting: true,
                    cell: ({ row }) => (
                        <div className="grid leading-5">
                            <span className="font-mono text-sm font-medium">{row.original.reference}</span>
                            <span className="text-muted-foreground text-xs">
                                {[text(row.original.title), showShop ? row.original.shop : null].filter(Boolean).join(' · ')}
                            </span>
                        </div>
                    ),
                    meta: { mobile: 'title' },
                },
                {
                    id: 'redeemed_at',
                    header: 'Taken',
                    enableSorting: true,
                    cell: ({ row }) => formatDateTime(text(row.original.redeemedAt)),
                },
                amount('amount', 'Value', 'gross', true),
                {
                    id: 'claimed_at',
                    header: 'Claimed',
                    enableSorting: true,
                    cell: ({ row }) => (text(row.original.claimedAt) ? formatDateTime(text(row.original.claimedAt)) : dash),
                },
                status,
            ];
    }
}

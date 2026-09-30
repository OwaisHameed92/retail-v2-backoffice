import { StatusPill } from '@/components/shared/status-badge';
import { cn } from '@/lib/utils';
import { type ColumnDef } from '@tanstack/react-table';
import { cost, formatDateTime, formatDay, money, PAYMENT_METHODS, PurchasingStatus, qty } from './format';
import { type DocumentRow, type PurchasingKind } from './types';

type Column = ColumnDef<DocumentRow>;

const dash = <span className="text-muted-foreground">—</span>;
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : null);

function reference(label: string, sortable = false, sub?: (row: DocumentRow) => string | null): Column {
    return {
        id: sortable ? 'reference' : 'ref',
        header: label,
        enableSorting: sortable,
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-mono text-sm font-medium">{row.original.reference}</span>
                {sub?.(row.original) && <span className="text-muted-foreground text-xs">{sub(row.original)}</span>}
            </div>
        ),
        meta: { mobile: 'title' },
    };
}

const supplier = (showShop: boolean): Column => ({
    id: 'supplier',
    header: 'Supplier',
    cell: ({ row }) => (
        <div className="grid max-w-64 leading-5">
            <span className="truncate">{row.original.supplier}</span>
            {showShop && row.original.shop && <span className="text-muted-foreground truncate text-xs">{row.original.shop}</span>}
        </div>
    ),
});

const day = (id: string, header: string, key = 'date', sortable = true): Column => ({
    id,
    header,
    enableSorting: sortable,
    cell: ({ row }) => (text(row.original[key]) ? formatDay(text(row.original[key])) : dash),
});

const amount = (id: string, header: string, key = 'gross', sortable = true): Column => ({
    id,
    header,
    enableSorting: sortable,
    cell: ({ row }) => <span className="tabular-nums">{money(row.original[key] as string | null)}</span>,
    meta: { align: 'right' },
});

const status: Column = { id: 'status', header: 'Status', cell: ({ row }) => <PurchasingStatus status={row.original.status} /> };

/** The list columns of each purchasing kind. Sortable ids are the server's sort keys. */
export function purchasingColumns(kind: PurchasingKind, showShop: boolean): Column[] {
    switch (kind) {
        case 'orders':
            return [
                reference('Order', true, (r) => (r.origin === 'headOffice' ? 'From head office' : null)),
                supplier(showShop),
                {
                    id: 'created_at',
                    header: 'Created',
                    enableSorting: true,
                    cell: ({ row }) => formatDateTime(text(row.original.createdAt)),
                },
                day('expected_date', 'Expected', 'expectedDate'),
                { id: 'lines', header: 'Lines', cell: ({ row }) => qty(row.original.lines as number), meta: { align: 'right' } },
                amount('gross_total', 'Total'),
                {
                    id: 'status',
                    header: 'Status',
                    cell: ({ row }) => (
                        <span className="flex flex-wrap items-center gap-1.5">
                            <PurchasingStatus status={row.original.status} />
                            {row.original.withPortal === true && <StatusPill tone="info">With the portal</StatusPill>}
                        </span>
                    ),
                },
            ];
        case 'deliveries':
            return [
                reference('Delivery note', false, (r) => (text(r.order) ? `Order ${r.order}` : 'No order')),
                supplier(showShop),
                day('received_date', 'Received'),
                { id: 'lines', header: 'Lines', cell: ({ row }) => qty(row.original.lines as number), meta: { align: 'right' } },
                {
                    id: 'damaged',
                    header: 'Damaged',
                    cell: ({ row }) => {
                        const n = Number(row.original.damaged ?? 0);

                        return (
                            <span className={cn('tabular-nums', n > 0 ? 'text-warning-foreground font-medium' : 'text-muted-foreground')}>
                                {n > 0 ? qty(n) : '—'}
                            </span>
                        );
                    },
                    meta: { align: 'right' },
                },
                amount('gross_amount', 'Value'),
                status,
            ];
        case 'invoices':
            return [
                reference('Invoice', false, (r) => (text(r.delivery) ? `Delivery ${r.delivery}` : null)),
                supplier(showShop),
                day('invoice_date', 'Date'),
                {
                    id: 'due_date',
                    header: 'Due',
                    enableSorting: true,
                    cell: ({ row }) => (
                        <span className={cn(row.original.overdue === true && 'text-danger-foreground font-medium')}>
                            {text(row.original.dueDate) ? formatDay(text(row.original.dueDate)) : '—'}
                        </span>
                    ),
                },
                amount('gross_amount', 'Total'),
                amount('balance', 'Balance', 'balance'),
                status,
            ];
        case 'credit-notes':
            return [
                reference('Credit note', false, (r) => text(r.reason)),
                supplier(showShop),
                day('credit_date', 'Date'),
                { id: 'invoice', header: 'Against invoice', cell: ({ row }) => text(row.original.invoice) ?? dash },
                amount('gross_amount', 'Credit'),
                amount('balance', 'Not yet used', 'balance'),
                status,
            ];
        case 'returns':
            return [
                reference('Return', true),
                supplier(showShop),
                day('return_date', 'Date'),
                { id: 'creditNote', header: 'Credit note', cell: ({ row }) => text(row.original.creditNote) ?? dash },
                amount('gross_amount', 'Value'),
                status,
            ];
        case 'payments':
            return [
                reference('Reference', false, (r) => PAYMENT_METHODS[String(r.method)] ?? null),
                supplier(showShop),
                day('payment_date', 'Paid'),
                {
                    id: 'amount',
                    header: 'Amount',
                    enableSorting: true,
                    cell: ({ row }) => (
                        <span className={cn('tabular-nums', row.original.status === 'reversed' && 'text-muted-foreground line-through')}>
                            {money(row.original.gross)}
                        </span>
                    ),
                    meta: { align: 'right' },
                },
                amount('unallocated', 'Not allocated', 'unallocated', false),
                status,
            ];
        case 'rebates':
            return [
                { ...reference('Agreement'), id: 'name', enableSorting: true },
                supplier(false),
                {
                    id: 'rate',
                    header: 'Rate',
                    enableSorting: true,
                    cell: ({ row }) =>
                        row.original.basis === 'perUnit' ? `${cost(text(row.original.rate))} a unit` : `${qty(text(row.original.rate))}%`,
                },
                {
                    id: 'period_to',
                    header: 'Period',
                    enableSorting: true,
                    cell: ({ row }) => `${formatDay(text(row.original.from))} – ${formatDay(text(row.original.to))}`,
                },
                amount('accrued', 'Accrued', 'gross', false),
                amount('unsettled', 'Not yet credited', 'unsettled', false),
                status,
            ];
    }
}

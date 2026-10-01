import { StatusPill } from '@/components/shared/status-badge';
import { type ColumnDef } from '@tanstack/react-table';
import { ArrowDownLeft, ArrowRight, ArrowUpRight } from 'lucide-react';
import { formatDateTime, lossClass, money, qty, RelayPill, TransferStatusBadge } from './format';
import { type TransferRow } from './types';

type Column = ColumnDef<TransferRow>;

const dash = <span className="text-muted-foreground">—</span>;

/** The transfer list's columns. Sortable ids are the server's sort keys. */
export function transferColumns(): Column[] {
    return [
        {
            id: 'reference',
            header: 'Transfer',
            enableSorting: true,
            cell: ({ row }) => (
                <div className="grid leading-5">
                    <span className="font-mono text-sm font-medium">{row.original.reference}</span>
                    <span className="text-muted-foreground text-xs">
                        {row.original.lines} {row.original.lines === 1 ? 'line' : 'lines'}
                        {row.original.isReturn ? ' · Return' : ''}
                    </span>
                </div>
            ),
            meta: { mobile: 'title' },
        },
        {
            id: 'route',
            header: 'From → to',
            cell: ({ row }) => (
                <div className="flex max-w-72 items-center gap-1.5">
                    {row.original.direction === 'out' && <ArrowUpRight className="text-muted-foreground size-4 shrink-0" aria-label="Sent" />}
                    {row.original.direction === 'in' && <ArrowDownLeft className="text-primary size-4 shrink-0" aria-label="Received" />}
                    <span className="truncate">{row.original.from}</span>
                    <ArrowRight className="text-muted-foreground size-3.5 shrink-0" aria-hidden />
                    <span className="truncate">{row.original.to}</span>
                </div>
            ),
        },
        {
            id: 'requested_at',
            header: 'Raised',
            enableSorting: true,
            cell: ({ row }) => formatDateTime(row.original.requestedAt),
        },
        {
            id: 'dispatched_at',
            header: 'Dispatched',
            enableSorting: true,
            cell: ({ row }) => (row.original.dispatchedAt ? formatDateTime(row.original.dispatchedAt) : dash),
        },
        {
            id: 'received',
            header: 'Received',
            cell: ({ row }) => (row.original.receivedAt ? formatDateTime(row.original.receivedAt) : dash),
        },
        {
            id: 'dispatched_cost',
            header: 'Value at cost',
            enableSorting: true,
            cell: ({ row }) => <span className="tabular-nums">{money(row.original.value)}</span>,
            meta: { align: 'right' },
        },
        {
            id: 'variance',
            header: 'Lost in transit',
            cell: ({ row }) =>
                row.original.varianceCost === null ? (
                    dash
                ) : (
                    <div className="grid justify-items-end leading-5">
                        <span className={lossClass(row.original.varianceCost)}>{money(row.original.varianceCost)}</span>
                        {row.original.discrepancies > 0 && (
                            <span className="text-muted-foreground text-xs">
                                {qty(row.original.discrepancies)} {row.original.discrepancies === 1 ? 'line' : 'lines'}
                            </span>
                        )}
                    </div>
                ),
            meta: { align: 'right' },
        },
        {
            id: 'status',
            header: 'Status',
            cell: ({ row }) => (
                <span className="flex flex-wrap items-center gap-1.5">
                    <TransferStatusBadge status={row.original.status} />
                    {row.original.discrepancies > 0 && <StatusPill tone="danger">Discrepancy</StatusPill>}
                </span>
            ),
            meta: { mobile: 'aside' },
        },
        {
            id: 'relay',
            header: 'Receiving till',
            cell: ({ row }) => (row.original.relay === 'notRelayed' ? dash : <RelayPill state={row.original.relay} />),
        },
    ];
}

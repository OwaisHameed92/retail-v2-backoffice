import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, type Paginated, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { londonDateTime } from '@/components/till-health/format';
import { type ColumnDef } from '@tanstack/react-table';
import { ReceiptText } from 'lucide-react';
import { useMemo } from 'react';
import { BalanceText, SignedAmount, number } from './format';
import { type CustomerShowProps, type LedgerRow, type Option } from './types';

const ONLY = ['ledger', 'ledgerFilters'];
const TYPE_OPTIONS: Option[] = [
    { value: 'account', label: 'Money only' },
    { value: 'points', label: 'Points only' },
];

/** Every ledger row of the customer from every shop, newest first, with the portal's running balance. */
export function LedgerTable({
    ledger,
    filters,
    shops,
}: {
    ledger: Paginated<LedgerRow>;
    filters: CustomerShowProps['ledgerFilters'];
    shops: Option[];
}) {
    const { update } = useTableQuery({ only: ONLY });
    const filtered = filters.shop !== null || filters.type !== null;

    const columns = useMemo<ColumnDef<LedgerRow>[]>(
        () => [
            {
                id: 'at',
                header: 'When',
                cell: ({ row }) => <span className="text-sm whitespace-nowrap">{londonDateTime(row.original.at)}</span>,
                meta: { mobile: 'title' },
            },
            {
                id: 'type',
                header: 'What',
                cell: ({ row }) => (
                    <div className="grid max-w-72 leading-5">
                        <span className="font-medium">
                            {row.original.typeLabel}
                            {row.original.tender && <span className="text-muted-foreground font-normal"> · {row.original.tender}</span>}
                        </span>
                        {row.original.note && <span className="text-muted-foreground truncate text-xs">{row.original.note}</span>}
                    </div>
                ),
            },
            { id: 'shop', header: 'Shop', cell: ({ row }) => row.original.shop },
            { id: 'amount', header: 'Amount', meta: { align: 'right' }, cell: ({ row }) => <SignedAmount value={row.original.amount} /> },
            {
                id: 'balanceAfter',
                header: 'Balance',
                meta: { align: 'right', mobile: filtered ? 'hidden' : 'field' },
                cell: ({ row }) =>
                    row.original.balanceAfter === null ? (
                        <span className="text-muted-foreground">—</span>
                    ) : (
                        <BalanceText balance={row.original.balanceAfter} short />
                    ),
            },
            { id: 'points', header: 'Points', meta: { align: 'right' }, cell: ({ row }) => <SignedAmount value={row.original.points} points /> },
            {
                id: 'pointsAfter',
                header: 'Points after',
                meta: { align: 'right', mobile: 'hidden' },
                cell: ({ row }) =>
                    row.original.pointsAfter === null ? (
                        <span className="text-muted-foreground">—</span>
                    ) : (
                        <span className="tabular-nums">{number(row.original.pointsAfter)}</span>
                    ),
            },
        ],
        [filtered],
    );

    return (
        <DataTable
            columns={columns}
            data={ledger.data}
            meta={ledger.meta}
            only={ONLY}
            searchable={false}
            filters={
                <>
                    {shops.length > 1 && (
                        <FilterSelect
                            value={filters.shop}
                            onChange={(shop) => update({ shop, page: 1 })}
                            all="Every shop"
                            options={shops}
                            label="Filter by shop"
                        />
                    )}
                    <FilterSelect
                        value={filters.type}
                        onChange={(type) => update({ type, page: 1 })}
                        all="Money and points"
                        options={TYPE_OPTIONS}
                        label="Filter by kind"
                    />
                </>
            }
            getRowId={(row) => row.id}
            empty={
                filtered ? undefined : (
                    <EmptyState
                        icon={ReceiptText}
                        title="No account activity yet"
                        body="Account sales, payments and points from every shop's tills appear here after each sync."
                    />
                )
            }
        />
    );
}

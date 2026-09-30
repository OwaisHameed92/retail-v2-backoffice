import { AccountsFilters, AccountsPageLayout, Amount } from '@/components/app/accounts/accounts-page';
import { type ExpenseRow, type ExpensesProps } from '@/components/app/accounts/types';
import { formatDay } from '@/components/app/pricing/format';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { money, number } from '@/components/shared/trading/format';
import { type ColumnDef } from '@tanstack/react-table';
import { Receipt, ReceiptText, Wallet } from 'lucide-react';

const PAID_BY: Record<string, string> = { cash: 'Cash from the till', card: 'Card', bank: 'Bank', owner: 'Paid by the owner' };

const columns: ColumnDef<ExpenseRow>[] = [
    {
        id: 'expense_date',
        accessorKey: 'date',
        header: 'Date',
        enableSorting: true,
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-medium">{row.original.payee ?? 'No payee'}</span>
                <span className="text-muted-foreground text-xs">{formatDay(row.original.date)}</span>
            </div>
        ),
    },
    {
        id: 'reason',
        header: 'What for',
        cell: ({ row }) => (
            <div className="grid max-w-64 gap-1 leading-5">
                <span className="truncate">{row.original.reason ?? row.original.note ?? '—'}</span>
                <span className="flex flex-wrap gap-1.5">
                    {row.original.voided && <StatusPill tone="neutral">Voided{row.original.voidReason ? `: ${row.original.voidReason}` : ''}</StatusPill>}
                    {!row.original.voided && !row.original.vatReceipt && Number(row.original.vat ?? 0) !== 0 && (
                        <StatusPill tone="warning">No VAT receipt</StatusPill>
                    )}
                </span>
            </div>
        ),
    },
    {
        id: 'shop',
        header: 'Shop',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => (
            <div className="grid text-sm leading-5">
                <span>{row.original.shop ?? 'Unknown shop'}</span>
                <span className="text-muted-foreground text-xs">{row.original.paidBy ? PAID_BY[row.original.paidBy] : '—'}</span>
            </div>
        ),
    },
    { id: 'net', accessorKey: 'net', header: 'Net', enableSorting: true, meta: { align: 'right', mobile: 'hidden' }, cell: ({ row }) => <Amount value={row.original.net} /> },
    {
        id: 'vat',
        header: 'VAT',
        meta: { align: 'right', mobile: 'hidden' },
        cell: ({ row }) => (
            <div className="grid justify-items-end leading-5">
                <Amount value={row.original.vat} />
                {row.original.vatRate && <span className="text-muted-foreground text-xs">{row.original.vatRate}</span>}
            </div>
        ),
    },
    {
        id: 'gross',
        accessorKey: 'gross',
        header: 'Total',
        enableSorting: true,
        meta: { align: 'right', mobile: 'aside' },
        cell: ({ row }) => <Amount value={row.original.gross} className={row.original.voided ? 'line-through' : undefined} />,
    },
];

export default function AccountsExpenses({ expenses, totals, filters, options }: ExpensesProps) {
    const { update, loading } = useTableQuery({ only: ['expenses', 'totals', 'filters', 'options'] });

    return (
        <AccountsPageLayout
            tab="expenses"
            filters={filters}
            title="Expenses · Accounts"
            description="Expenses recorded on your tills. The shop records, edits and voids them on the till; here they are read only."
        >
            <StatGrid columns={3}>
                <StatCard label="Expenses" value={money(totals.gross)} hint={`${number(totals.count)} not voided · ${money(totals.net)} before VAT`} icon={Wallet} />
                <StatCard label="VAT you can reclaim" value={money(totals.reclaimableVat)} hint="Where the shop holds a VAT receipt" icon={ReceiptText} tone="success" />
                <StatCard
                    label="VAT without a receipt"
                    value={money(totals.unreclaimedVat)}
                    hint="Not counted in the VAT return"
                    icon={Receipt}
                    tone="warning"
                />
            </StatGrid>
            <DataTable
                columns={columns}
                data={expenses.data}
                meta={expenses.meta}
                onChange={update}
                loading={loading}
                searchable
                searchPlaceholder="Search payee, receipt or note"
                filters={<AccountsFilters filters={filters} options={options} update={update} />}
                getRowId={(row) => row.id}
                empty={<EmptyState icon={Wallet} title="No expenses in these dates" body="Expenses appear here once a till has recorded and synced them." />}
            />
        </AccountsPageLayout>
    );
}

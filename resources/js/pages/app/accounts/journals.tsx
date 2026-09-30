import { AccountsFilters, AccountsPageLayout, Amount, RefundFixNotice } from '@/components/app/accounts/accounts-page';
import { JournalFlags } from '@/components/app/accounts/journal-flags';
import { type JournalRow, type JournalsProps } from '@/components/app/accounts/types';
import { formatDay } from '@/components/app/pricing/format';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { NotebookText } from 'lucide-react';

const columns: ColumnDef<JournalRow>[] = [
    {
        id: 'date',
        accessorKey: 'date',
        header: 'Date',
        enableSorting: true,
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-medium">{formatDay(row.original.date)}</span>
                <span className="text-muted-foreground text-xs">{row.original.refType ?? 'Journal'}</span>
            </div>
        ),
    },
    {
        id: 'memo',
        header: 'Details',
        cell: ({ row }) => (
            <div className="grid max-w-md gap-1 leading-5">
                <span className="truncate">{row.original.memo ?? '—'}</span>
                <JournalFlags entry={row.original} />
            </div>
        ),
    },
    {
        id: 'where',
        header: 'Shop and till',
        meta: { label: 'Where', mobile: 'hidden' },
        cell: ({ row }) => (
            <div className="grid max-w-48 text-sm leading-5">
                <span className="truncate">{row.original.shop ?? 'Unknown shop'}</span>
                <span className="text-muted-foreground truncate text-xs">{row.original.till ?? '—'}</span>
            </div>
        ),
    },
    {
        id: 'total_debits',
        accessorKey: 'debits',
        header: 'Amount',
        enableSorting: true,
        meta: { align: 'right', mobile: 'aside' },
        cell: ({ row }) => <Amount value={row.original.debits} />,
    },
];

export default function AccountsJournals({ entries, filters, options, refTypes, accounts, refundFix }: JournalsProps) {
    const { update, loading } = useTableQuery({ only: ['entries', 'filters', 'options', 'refTypes', 'accounts', 'refundFix'] });

    return (
        <AccountsPageLayout
            tab="journals"
            filters={filters}
            title="Journals · Accounts"
            description="The double-entry journals your tills post for sales, refunds, cash and expenses. Read only: they are the tills' records."
        >
            <RefundFixNotice summary={refundFix} fix={false} />
            <DataTable
                columns={columns}
                data={entries.data}
                meta={entries.meta}
                onChange={update}
                loading={loading}
                searchable
                searchPlaceholder="Search details or reference"
                filters={
                    <AccountsFilters filters={filters} options={options} update={update}>
                        {refTypes.length > 1 && (
                            <FilterSelect value={filters.refType} onChange={(refType) => update({ refType, page: undefined })} all="Every type" options={refTypes} label="Filter by type" />
                        )}
                        {accounts.length > 0 && (
                            <FilterSelect
                                value={filters.account}
                                onChange={(account) => update({ account, page: undefined })}
                                all="Every account"
                                options={accounts}
                                label="Filter by account"
                                width="sm:w-60"
                            />
                        )}
                    </AccountsFilters>
                }
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.accounts.journals.show', row.id))}
                empty={<EmptyState icon={NotebookText} title="No journals in these dates" body="Journals appear here once a till has posted and synced them." />}
            />
        </AccountsPageLayout>
    );
}

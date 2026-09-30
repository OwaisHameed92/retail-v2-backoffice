import { AccountsFilters, AccountsPageLayout, Amount } from '@/components/app/accounts/accounts-page';
import { type FixedAssetRow, type FixedAssetsProps } from '@/components/app/accounts/types';
import { formatDay } from '@/components/app/pricing/format';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { money, number } from '@/components/shared/trading/format';
import { type ColumnDef } from '@tanstack/react-table';
import { Armchair, Package } from 'lucide-react';

const columns: ColumnDef<FixedAssetRow>[] = [
    {
        id: 'name',
        accessorKey: 'name',
        header: 'Asset',
        enableSorting: true,
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-medium">{row.original.name}</span>
                <span className="text-muted-foreground text-xs">
                    {[row.original.code, row.original.category].filter(Boolean).join(' · ') || 'No category'}
                </span>
            </div>
        ),
    },
    { id: 'shop', header: 'Shop', meta: { mobile: 'hidden' }, cell: ({ row }) => row.original.shop ?? 'Unknown shop' },
    {
        id: 'purchase_date',
        accessorKey: 'purchaseDate',
        header: 'Bought',
        enableSorting: true,
        cell: ({ row }) => formatDay(row.original.purchaseDate),
    },
    {
        id: 'depreciation',
        header: 'Depreciation',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => {
            const a = row.original;
            const parts = [a.usefulLifeYears ? `${a.usefulLifeYears} years` : null, a.depreciationRate ? `${Number(a.depreciationRate)}% a year` : null].filter(Boolean);

            return (
                <div className="grid text-sm leading-5">
                    <span>{parts.join(' · ') || '—'}</span>
                    {a.residual && Number(a.residual) !== 0 && <span className="text-muted-foreground text-xs">Residual {money(a.residual)}</span>}
                </div>
            );
        },
    },
    {
        id: 'status',
        header: 'Status',
        cell: ({ row }) =>
            row.original.disposalDate ? (
                <StatusPill tone="neutral">
                    Disposed {formatDay(row.original.disposalDate)}
                    {row.original.disposalProceeds ? ` for ${money(row.original.disposalProceeds)}` : ''}
                </StatusPill>
            ) : row.original.isActive ? (
                <StatusPill tone="success">In use</StatusPill>
            ) : (
                <StatusPill tone="warning">Not in use</StatusPill>
            ),
    },
    {
        id: 'purchase_cost_amount',
        accessorKey: 'cost',
        header: 'Cost',
        enableSorting: true,
        meta: { align: 'right', mobile: 'aside' },
        cell: ({ row }) => <Amount value={row.original.cost} />,
    },
];

export default function AccountsFixedAssets({ assets, totals, filters, options }: FixedAssetsProps) {
    const { update, loading } = useTableQuery({ only: ['assets', 'totals', 'filters', 'options'] });

    return (
        <AccountsPageLayout
            tab="assets"
            filters={filters}
            title="Fixed assets · Accounts"
            description="Equipment and fittings the shops keep on their tills' asset register. Read only: they are added and disposed of on the till."
        >
            <StatGrid columns={2}>
                <StatCard label="Assets held" value={number(totals.held)} hint="Not disposed of" icon={Package} />
                <StatCard label="Cost of assets held" value={money(totals.cost)} icon={Armchair} tone="neutral" />
            </StatGrid>
            <DataTable
                columns={columns}
                data={assets.data}
                meta={assets.meta}
                onChange={update}
                loading={loading}
                searchable
                searchPlaceholder="Search name or code"
                filters={<AccountsFilters filters={filters} options={options} update={update} dates={false} />}
                getRowId={(row) => row.id}
                empty={<EmptyState icon={Armchair} title="No fixed assets" body="Assets appear here once a shop adds them on its till and syncs." />}
            />
        </AccountsPageLayout>
    );
}

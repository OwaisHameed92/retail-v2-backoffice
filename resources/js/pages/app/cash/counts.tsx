import { CashFilters, CashPageLayout } from '@/components/app/cash/cash-page';
import { dash, formatDateTime, formatDay, Money, money, number, Variance } from '@/components/app/cash/format';
import { type CountRow, type CountsProps } from '@/components/app/cash/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { type ColumnDef } from '@tanstack/react-table';
import { Scale, TrendingDown, TrendingUp, Vault } from 'lucide-react';

const columns: ColumnDef<CountRow>[] = [
    {
        id: 'day',
        header: 'Trading day',
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-medium">{formatDay(row.original.day)}</span>
                <span className="text-muted-foreground text-xs">
                    Counted {formatDateTime(row.original.countedAt)}
                    {row.original.countedBy && ` by ${row.original.countedBy}`}
                </span>
            </div>
        ),
    },
    { id: 'shop', header: 'Shop', cell: ({ row }) => row.original.shop ?? 'Unknown shop' },
    { id: 'expected', header: 'Expected', meta: { align: 'right' }, cell: ({ row }) => <Money value={row.original.expected} /> },
    { id: 'counted', header: 'Counted', meta: { align: 'right' }, cell: ({ row }) => <Money value={row.original.counted} className="font-medium" /> },
    { id: 'variance', header: 'Variance', meta: { align: 'right', mobile: 'aside' }, cell: ({ row }) => <Variance value={row.original.variance} /> },
    {
        id: 'why',
        header: 'Reason and note',
        meta: { mobile: 'field' },
        cell: ({ row }) => (
            <span className="block max-w-56 truncate text-sm">{[row.original.reason, row.original.note].filter(Boolean).join(' · ') || dash}</span>
        ),
    },
    {
        id: 'denominations',
        header: 'Notes and coins',
        meta: { mobile: 'hidden' },
        cell: ({ row }) =>
            row.original.denominations.length ? (
                <span className="text-muted-foreground block max-w-64 truncate text-xs tabular-nums">
                    {row.original.denominations.map((d) => `${money(d.denomination)} × ${number(d.count)}`).join(', ')}
                </span>
            ) : (
                dash
            ),
    },
];

export default function CashCounts({ counts, summary, filters, options }: CountsProps) {
    const { update, loading } = useTableQuery({ only: ['counts', 'summary', 'filters', 'options'] });

    return (
        <CashPageLayout
            tab="counts"
            filters={filters}
            title="Safe counts · Cash and Z"
            description="Counts of the safe and cash office against what the till expected. Negative = short, positive = over. Read only."
        >
            <StatGrid>
                <StatCard label="Counts" value={number(summary.count)} icon={Vault} tone="primary" />
                <StatCard
                    label="Net variance"
                    value={<Variance value={summary.variance} />}
                    icon={Scale}
                    tone={Number(summary.variance) < 0 ? 'danger' : 'success'}
                />
                <StatCard label="Short" value={number(summary.short)} icon={TrendingDown} tone={summary.short > 0 ? 'danger' : 'neutral'} />
                <StatCard label="Over" value={number(summary.over)} icon={TrendingUp} tone={summary.over > 0 ? 'warning' : 'neutral'} />
            </StatGrid>
            <DataTable
                columns={columns}
                data={counts.data}
                meta={counts.meta}
                onChange={update}
                loading={loading}
                filters={<CashFilters filters={filters} options={options} update={update} showTill={false} />}
                getRowId={(row) => row.id}
                empty={
                    <EmptyState
                        icon={Vault}
                        title="No safe counts in these dates"
                        body="A safe or cash office count appears here once a shop records it on its till and syncs."
                    />
                }
            />
        </CashPageLayout>
    );
}

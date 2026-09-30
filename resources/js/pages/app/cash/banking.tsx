import { CashFilters, CashPageLayout } from '@/components/app/cash/cash-page';
import { BANKING_STATUS, dash, formatDateTime, Money, money, number, Variance } from '@/components/app/cash/format';
import { type BankingProps, type BankingRow } from '@/components/app/cash/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { type ColumnDef } from '@tanstack/react-table';
import { Landmark, Scale, Truck, Wallet } from 'lucide-react';

const columns: ColumnDef<BankingRow>[] = [
    {
        id: 'reference',
        header: 'Banking',
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-medium">{row.original.reference ?? 'No reference'}</span>
                <span className="text-muted-foreground text-xs">
                    Prepared {formatDateTime(row.original.preparedAt)}
                    {row.original.preparedBy && ` by ${row.original.preparedBy}`}
                </span>
            </div>
        ),
    },
    {
        id: 'status',
        header: 'Status',
        meta: { mobile: 'aside' },
        cell: ({ row }) => {
            const s = BANKING_STATUS[row.original.status ?? ''];
            return s ? <StatusPill tone={s.tone}>{s.label}</StatusPill> : dash;
        },
    },
    { id: 'shop', header: 'Shop', cell: ({ row }) => row.original.shop ?? 'Unknown shop' },
    {
        id: 'method',
        header: 'Collection',
        cell: ({ row }) => (
            <div className="grid text-sm leading-5">
                <span>
                    {row.original.method === 'carrier'
                        ? `Carrier${row.original.carrier ? `: ${row.original.carrier}` : ''}`
                        : row.original.method === 'ownBanking'
                          ? 'Own banking'
                          : '—'}
                </span>
                <span className="text-muted-foreground text-xs">
                    {row.original.collectedAt
                        ? `Collected ${formatDateTime(row.original.collectedAt)}${row.original.collectedBy ? ` · ${row.original.collectedBy}` : ''}`
                        : ''}
                    {row.original.sealNumber && ` · Seal ${row.original.sealNumber}`}
                </span>
            </div>
        ),
    },
    {
        id: 'banked',
        header: 'Banked',
        meta: { mobile: 'hidden' },
        cell: ({ row }) =>
            row.original.bankedAt ? (
                <div className="grid text-sm leading-5">
                    <span>{formatDateTime(row.original.bankedAt)}</span>
                    <span className="text-muted-foreground text-xs">
                        {[row.original.bankedBy, row.original.bankReference].filter(Boolean).join(' · ')}
                    </span>
                </div>
            ) : (
                dash
            ),
    },
    { id: 'amount', header: 'Amount', meta: { align: 'right' }, cell: ({ row }) => <Money value={row.original.amount} className="font-medium" /> },
    {
        id: 'confirmed',
        header: 'Bank confirmed',
        meta: { align: 'right', mobile: 'hidden' },
        cell: ({ row }) => <Money value={row.original.confirmedAmount} />,
    },
    { id: 'variance', header: 'Variance', meta: { align: 'right' }, cell: ({ row }) => <Variance value={row.original.variance} /> },
];

export default function CashBanking({ bankings, summary, filters, options }: BankingProps) {
    const { update, loading } = useTableQuery({ only: ['bankings', 'summary', 'filters', 'options'] });

    return (
        <CashPageLayout
            tab="banking"
            filters={filters}
            title="Banking · Cash and Z"
            description="Cash taken from the cash office to the bank: prepared, collected and banked, as the shop recorded it. Read only."
        >
            <StatGrid>
                <StatCard label="Bankings" value={number(summary.count)} hint="Not cancelled" icon={Landmark} tone="primary" />
                <StatCard label="Total prepared" value={money(summary.total)} icon={Wallet} tone="primary" />
                <StatCard
                    label="Not banked yet"
                    value={number(summary.waiting)}
                    hint="Prepared or in transit"
                    icon={Truck}
                    tone={summary.waiting > 0 ? 'warning' : 'neutral'}
                />
                <StatCard
                    label="Bank differences"
                    value={<Variance value={summary.variance} />}
                    hint="Confirmed − prepared"
                    icon={Scale}
                    tone={Number(summary.variance) < 0 ? 'danger' : 'neutral'}
                />
            </StatGrid>
            <DataTable
                columns={columns}
                data={bankings.data}
                meta={bankings.meta}
                onChange={update}
                loading={loading}
                filters={<CashFilters filters={filters} options={options} update={update} showTill={false} />}
                getRowId={(row) => row.id}
                empty={
                    <EmptyState
                        icon={Landmark}
                        title="No bankings in these dates"
                        body="Bankings appear here once a shop prepares one on its till and syncs."
                    />
                }
            />
        </CashPageLayout>
    );
}

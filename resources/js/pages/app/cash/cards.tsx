import { CashFilters, CashPageLayout } from '@/components/app/cash/cash-page';
import { dash, formatDateTime, formatDay, Money, money, number, Variance } from '@/components/app/cash/format';
import { type CardDayRow, type CardFlag, type CardsProps } from '@/components/app/cash/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { type ColumnDef } from '@tanstack/react-table';
import { CreditCard, Scale, TriangleAlert } from 'lucide-react';

const FLAGS: Record<CardFlag, { label: string; tone: StatusTone; help: string }> = {
    matched: { label: 'Matched', tone: 'success', help: 'Settled equals the card takings.' },
    difference: { label: 'Difference', tone: 'danger', help: 'Settled and card takings differ.' },
    notSettled: { label: 'Not settled', tone: 'warning', help: 'Card takings but no settlement sent.' },
    failed: { label: 'Settlement failed', tone: 'danger', help: 'A settlement failed on the terminal.' },
    noTillTotal: { label: 'No till total', tone: 'warning', help: 'A settlement but no closed shift with card takings.' },
    mismatched: { label: 'Till flagged', tone: 'warning', help: 'The till marked the settlement mismatched.' },
};

const columns: ColumnDef<CardDayRow>[] = [
    {
        id: 'day',
        header: 'Trading day',
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-medium">{formatDay(row.original.day)}</span>
                <span className="text-muted-foreground text-xs">
                    {[row.original.shop, row.original.till ?? 'Unknown till'].filter(Boolean).join(' · ')}
                </span>
            </div>
        ),
    },
    {
        id: 'flag',
        header: 'Check',
        meta: { mobile: 'aside' },
        cell: ({ row }) => {
            const f = FLAGS[row.original.flag];
            return (
                <span title={f.help}>
                    <StatusPill tone={f.tone}>{f.label}</StatusPill>
                </span>
            );
        },
    },
    {
        id: 'tillCard',
        header: 'Till card takings',
        meta: { align: 'right' },
        cell: ({ row }) => (
            <div className="grid justify-items-end leading-5">
                <Money value={row.original.tillCard} />
                {row.original.shifts > 0 && (
                    <span className="text-muted-foreground text-xs">
                        {number(row.original.shifts)} {row.original.shifts === 1 ? 'shift' : 'shifts'}
                    </span>
                )}
            </div>
        ),
    },
    { id: 'settled', header: 'Settled', meta: { align: 'right' }, cell: ({ row }) => <Money value={row.original.settled} /> },
    { id: 'difference', header: 'Difference', meta: { align: 'right' }, cell: ({ row }) => <Variance value={row.original.difference} /> },
    {
        id: 'settlements',
        header: 'Settlements',
        meta: { mobile: 'field' },
        cell: ({ row }) =>
            row.original.settlements.length ? (
                <ul className="grid gap-1 text-xs">
                    {row.original.settlements.map((s) => (
                        <li key={s.id} className="leading-4">
                            <span className="font-medium">{[s.provider, s.batch ?? s.reference].filter(Boolean).join(' · ') || 'Settlement'}</span>{' '}
                            <span className="text-muted-foreground">
                                {money(s.terminal)} terminal · {money(s.pos)} till · {number(s.transactions)} txns
                                {s.fees !== null && Number(s.fees) !== 0 && ` · fees ${money(s.fees)}`} · {formatDateTime(s.settledAt)}
                                {s.settledBy && ` · ${s.settledBy}`}
                            </span>
                            {s.status && s.status !== 'matched' && (
                                <span className="text-danger-foreground">
                                    {' '}
                                    · {s.status === 'failed' ? 'Failed' : 'Mismatched'}
                                    {s.message && `: ${s.message}`}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            ) : (
                dash
            ),
    },
];

export default function CashCards({ days, summary, filters, options }: CardsProps) {
    const { update, loading } = useTableQuery({ only: ['days', 'summary', 'filters', 'options'] });

    return (
        <CashPageLayout
            tab="cards"
            filters={filters}
            title="Card settlements · Cash and Z"
            description="Each till's card takings for the day (its closed shifts) against what the card terminal settled. Differences are flagged."
        >
            <StatGrid>
                <StatCard label="Till days" value={number(summary.days)} icon={CreditCard} tone="primary" />
                <StatCard
                    label="Flagged"
                    value={number(summary.flagged)}
                    hint="Need a look"
                    icon={TriangleAlert}
                    tone={summary.flagged > 0 ? 'danger' : 'success'}
                />
                <StatCard
                    label="Card takings"
                    value={money(summary.tillCard)}
                    hint={`${money(summary.settled)} settled`}
                    icon={CreditCard}
                    tone="primary"
                />
                <StatCard
                    label="Difference"
                    value={<Variance value={summary.difference} />}
                    hint="Settled − takings"
                    icon={Scale}
                    tone={Number(summary.difference) !== 0 ? 'danger' : 'success'}
                />
            </StatGrid>
            <DataTable
                columns={columns}
                data={days.data}
                meta={days.meta}
                onChange={update}
                loading={loading}
                filters={<CashFilters filters={filters} options={options} update={update} />}
                getRowId={(row) => row.key}
                empty={
                    <EmptyState
                        icon={CreditCard}
                        title="Nothing to reconcile in these dates"
                        body="Days appear here once a till closes a shift with card takings or sends a card settlement."
                    />
                }
            />
        </CashPageLayout>
    );
}

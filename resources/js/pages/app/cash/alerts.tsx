import { CashFilters, CashPageLayout } from '@/components/app/cash/cash-page';
import { AlertFlag, formatDateTime, money, number, Variance } from '@/components/app/cash/format';
import { type AlertRow, type AlertsProps } from '@/components/app/cash/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { currencySymbol, formatMoneyAsGiven } from '@/lib/country';
import { router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Clock, ShieldCheck, TrendingDown, TriangleAlert, Vault } from 'lucide-react';
import { type FormEvent, useState } from 'react';

const KIND = { shift: 'Shift', safeCount: 'Safe count', banking: 'Banking' } as const;

const columns: ColumnDef<AlertRow>[] = [
    {
        id: 'when',
        header: 'When',
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-medium">{formatDateTime(row.original.at)}</span>
                <span className="text-muted-foreground text-xs">{[row.original.shop, row.original.till].filter(Boolean).join(' · ')}</span>
            </div>
        ),
    },
    {
        id: 'kind',
        header: 'Type',
        cell: ({ row }) => <StatusPill tone={row.original.kind === 'shift' ? 'info' : 'violet'}>{KIND[row.original.kind]}</StatusPill>,
    },
    {
        id: 'what',
        header: 'What',
        meta: { mobile: 'field' },
        cell: ({ row }) => (
            <div className="grid gap-0.5 text-sm">
                <span>{row.original.what}</span>
                {row.original.tillFlag && <AlertFlag label="Flagged by the till" />}
            </div>
        ),
    },
    { id: 'variance', header: 'Variance', meta: { align: 'right', mobile: 'aside' }, cell: ({ row }) => <Variance value={row.original.variance} /> },
    {
        id: 'threshold',
        header: 'Alert at',
        meta: { align: 'right', mobile: 'hidden' },
        cell: ({ row }) => <span className="text-muted-foreground tabular-nums">{money(row.original.threshold)}</span>,
    },
];

function ThresholdForm({
    value,
    fallback,
    update,
}: {
    value: string | null;
    fallback: string;
    update: (p: { threshold?: string; page?: undefined }) => void;
}) {
    const [draft, setDraft] = useState(value ?? '');
    const submit = (e: FormEvent) => {
        e.preventDefault();
        update({ threshold: draft.trim() || undefined, page: undefined });
    };

    return (
        <form onSubmit={submit} className="flex items-center gap-1.5">
            <Input
                inputMode="decimal"
                className="h-9 w-full sm:w-32"
                aria-label={`Alert at (${currencySymbol()})`}
                placeholder={formatMoneyAsGiven(fallback)}
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
            />
            <Button type="submit" variant="outline" size="sm" className="h-9">
                Apply
            </Button>
        </form>
    );
}

export default function CashAlerts({ alerts, summary, filters, options }: AlertsProps) {
    const { update, loading } = useTableQuery({ only: ['alerts', 'summary', 'filters', 'options'] });
    const rule = summary.threshold
        ? `at or above ${money(summary.threshold)} (your choice)`
        : `at or above each shop's own “alert when over or short” till setting, else ${money(summary.defaultThreshold)}`;

    return (
        <CashPageLayout
            tab="alerts"
            filters={filters}
            title="Variance alerts · Cash and Z"
            description={`Shifts, safe counts and bankings over or short by an amount ${rule}. Shifts the till flagged itself are always listed.`}
        >
            <StatGrid>
                <StatCard label="Alerts" value={number(summary.total)} icon={TriangleAlert} tone={summary.total > 0 ? 'danger' : 'success'} />
                <StatCard
                    label="Short"
                    value={number(summary.short)}
                    hint="Money missing"
                    icon={TrendingDown}
                    tone={summary.short > 0 ? 'danger' : 'neutral'}
                />
                <StatCard label="Shifts" value={number(summary.shifts)} icon={Clock} tone="primary" />
                <StatCard label="Cash office" value={number(summary.office)} hint="Safe counts and bankings" icon={Vault} tone="primary" />
            </StatGrid>
            <DataTable
                columns={columns}
                data={alerts.data}
                meta={alerts.meta}
                onChange={update}
                loading={loading}
                filters={
                    <CashFilters filters={filters} options={options} update={update}>
                        <ThresholdForm key={filters.threshold ?? ''} value={filters.threshold} fallback={summary.defaultThreshold} update={update} />
                    </CashFilters>
                }
                getRowId={(row) => `${row.kind}-${row.id}`}
                onRowClick={(row) => row.shiftId && router.visit(route('app.cash.shifts.show', row.shiftId))}
                empty={
                    <EmptyState icon={ShieldCheck} title="No variance alerts" body="Nothing was over or short by the alert amount in these dates." />
                }
            />
        </CashPageLayout>
    );
}

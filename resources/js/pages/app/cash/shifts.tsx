import { CashFilters, CashPageLayout } from '@/components/app/cash/cash-page';
import { AlertFlag, dash, formatDateTime, Money, money, number, ShiftStatus, Variance } from '@/components/app/cash/format';
import { type ShiftRow, type ShiftsProps } from '@/components/app/cash/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Banknote, Clock, Scale, TrendingDown, Wallet } from 'lucide-react';

const STATUS_OPTIONS = [
    { value: 'open', label: 'Open now' },
    { value: 'closed', label: 'Closed' },
];

const columns: ColumnDef<ShiftRow>[] = [
    {
        id: 'opened',
        header: 'Shift',
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-medium">{formatDateTime(row.original.openedAt)}</span>
                <span className="text-muted-foreground text-xs">
                    {row.original.closedAt ? `Closed ${formatDateTime(row.original.closedAt)}` : 'Still open'}
                </span>
            </div>
        ),
    },
    { id: 'status', header: 'Status', meta: { mobile: 'aside' }, cell: ({ row }) => <ShiftStatus status={row.original.status} /> },
    {
        id: 'where',
        header: 'Shop and till',
        meta: { label: 'Where' },
        cell: ({ row }) => (
            <div className="grid max-w-48 text-sm leading-5">
                <span className="truncate">{row.original.shop ?? 'Unknown shop'}</span>
                <span className="text-muted-foreground truncate text-xs">{row.original.till ?? 'Unknown till'}</span>
            </div>
        ),
    },
    { id: 'user', header: 'Staff', cell: ({ row }) => row.original.user ?? dash },
    { id: 'float', header: 'Float', meta: { align: 'right' }, cell: ({ row }) => <Money value={row.original.float} /> },
    {
        id: 'cashExpected',
        header: 'Cash expected',
        meta: { align: 'right', mobile: 'hidden' },
        cell: ({ row }) => <Money value={row.original.cashExpected} />,
    },
    {
        id: 'cashCounted',
        header: 'Cash counted',
        meta: { align: 'right', mobile: 'hidden' },
        cell: ({ row }) => <Money value={row.original.cashCounted} />,
    },
    { id: 'cashVariance', header: 'Cash variance', meta: { align: 'right' }, cell: ({ row }) => <Variance value={row.original.cashVariance} /> },
    {
        id: 'variance',
        header: 'All tenders',
        meta: { align: 'right', mobile: 'hidden' },
        cell: ({ row }) => <Variance value={row.original.variance} />,
    },
    {
        id: 'z',
        header: 'Z',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => (
            <div className="grid gap-0.5">
                <span className="tabular-nums">{row.original.z !== null ? `Z ${row.original.z}` : dash}</span>
                {row.original.warning && <AlertFlag />}
            </div>
        ),
    },
];

export default function CashShifts({ shifts, summary, filters, options }: ShiftsProps) {
    const { update, loading } = useTableQuery({ only: ['shifts', 'summary', 'filters', 'options'] });

    return (
        <CashPageLayout
            tab="shifts"
            filters={filters}
            title="Shifts · Cash and Z"
            description="Your tills' shifts: float, cash expected against counted and the difference, as each till worked them out. Read only."
        >
            <StatGrid>
                <StatCard
                    label={filters.status === 'open' ? 'Open shifts' : 'Shifts opened'}
                    value={number(summary.shifts)}
                    icon={Clock}
                    tone="primary"
                />
                <StatCard
                    label="Open now"
                    value={number(summary.open)}
                    hint="Any date"
                    icon={Wallet}
                    tone={summary.open > 0 ? 'warning' : 'neutral'}
                    href={route('app.cash.index', { from: filters.from, to: filters.to, status: 'open' })}
                />
                <StatCard
                    label="Cash variance"
                    value={<Variance value={summary.cashVariance} />}
                    hint="Closed shifts listed · negative = short"
                    icon={Scale}
                    tone={Number(summary.cashVariance) < 0 ? 'danger' : 'success'}
                />
                <StatCard
                    label="Shifts short"
                    value={number(summary.short)}
                    hint={`${number(summary.over)} over`}
                    icon={TrendingDown}
                    tone={summary.short > 0 ? 'danger' : 'neutral'}
                />
            </StatGrid>

            {summary.waitingCount > 0 && (
                <Alert variant="info">
                    <Banknote />
                    <AlertDescription>
                        {number(summary.waitingCount)} cash {summary.waitingCount === 1 ? 'movement' : 'movements'} ({money(summary.waitingTotal)})
                        taken while no shift was open. The till adds them to the next shift it opens; they then show on that shift as “before the
                        shift”.
                    </AlertDescription>
                </Alert>
            )}

            <DataTable
                columns={columns}
                data={shifts.data}
                meta={shifts.meta}
                onChange={update}
                loading={loading}
                filters={
                    <CashFilters filters={filters} options={options} update={update}>
                        <FilterSelect
                            value={filters.status}
                            onChange={(status) => update({ status, page: undefined })}
                            all="Any status"
                            options={STATUS_OPTIONS}
                            label="Filter by status"
                            width="sm:w-36"
                        />
                    </CashFilters>
                }
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.cash.shifts.show', row.id))}
                empty={
                    <EmptyState
                        icon={Clock}
                        title={filters.status === 'open' ? 'No shifts open' : 'No shifts in these dates'}
                        body="Shifts appear here once a till has synced them. Try other dates or every shop."
                    />
                }
            />
        </CashPageLayout>
    );
}

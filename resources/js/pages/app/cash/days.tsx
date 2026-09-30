import { CashFilters, CashPageLayout } from '@/components/app/cash/cash-page';
import { dash, formatDateTime, formatDay, number } from '@/components/app/cash/format';
import { type DayLockRow, type DaysProps } from '@/components/app/cash/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { type ColumnDef } from '@tanstack/react-table';
import { CalendarCheck, Info, LockKeyhole, LockOpen } from 'lucide-react';

const STATUS: Record<DayLockRow['status'], { label: string; tone: StatusTone }> = {
    locked: { label: 'Locked', tone: 'success' },
    unlocked: { label: 'Reopened', tone: 'warning' },
    open: { label: 'Not locked', tone: 'neutral' },
    today: { label: 'Trading', tone: 'info' },
};

const columns: ColumnDef<DayLockRow>[] = [
    {
        id: 'day',
        header: 'Trading day',
        meta: { mobile: 'title' },
        cell: ({ row }) => <span className="font-medium">{formatDay(row.original.day)}</span>,
    },
    { id: 'shop', header: 'Shop', cell: ({ row }) => row.original.shop },
    {
        id: 'status',
        header: 'Status',
        meta: { mobile: 'aside' },
        cell: ({ row }) => <StatusPill tone={STATUS[row.original.status].tone}>{STATUS[row.original.status].label}</StatusPill>,
    },
    {
        id: 'locked',
        header: 'Locked',
        cell: ({ row }) =>
            row.original.lockedAt ? (
                <div className="grid text-sm leading-5">
                    <span>{formatDateTime(row.original.lockedAt)}</span>
                    {row.original.lockedBy && <span className="text-muted-foreground text-xs">{row.original.lockedBy}</span>}
                </div>
            ) : (
                dash
            ),
    },
    {
        id: 'unlocked',
        header: 'Reopened',
        cell: ({ row }) =>
            row.original.unlockedAt ? (
                <div className="grid max-w-64 text-sm leading-5">
                    <span>
                        {formatDateTime(row.original.unlockedAt)}
                        {row.original.unlockedBy && ` · ${row.original.unlockedBy}`}
                    </span>
                    {row.original.reason && <span className="text-muted-foreground truncate text-xs">{row.original.reason}</span>}
                </div>
            ) : (
                dash
            ),
    },
];

export default function CashDays({ days, summary, filters, options }: DaysProps) {
    const { update, loading } = useTableQuery({ only: ['days', 'summary', 'filters', 'options'] });

    return (
        <CashPageLayout
            tab="days"
            filters={filters}
            title="Day locks · Cash and Z"
            description="Whether each shop has locked its trading day, so no more changes can be made to it on the till."
        >
            <Alert variant="info">
                <Info />
                <AlertDescription>
                    Days are locked and reopened on the shop's till by a manager. The portal shows the status; it cannot lock or reopen a day.
                </AlertDescription>
            </Alert>
            <StatGrid columns={3}>
                <StatCard label="Locked" value={number(summary.locked)} icon={LockKeyhole} tone="success" />
                <StatCard
                    label="Reopened"
                    value={number(summary.unlocked)}
                    hint="Unlocked after locking"
                    icon={LockOpen}
                    tone={summary.unlocked > 0 ? 'warning' : 'neutral'}
                />
                <StatCard
                    label="Not locked"
                    value={number(summary.open)}
                    hint="Past days"
                    icon={CalendarCheck}
                    tone={summary.open > 0 ? 'warning' : 'neutral'}
                />
            </StatGrid>
            <DataTable
                columns={columns}
                data={days.data}
                meta={days.meta}
                onChange={update}
                loading={loading}
                filters={<CashFilters filters={filters} options={options} update={update} showTill={false} />}
                getRowId={(row) => row.key}
                empty={
                    <EmptyState
                        icon={CalendarCheck}
                        title="No trading days in these dates"
                        body="Pick dates up to today to see each shop's day lock."
                    />
                }
            />
        </CashPageLayout>
    );
}

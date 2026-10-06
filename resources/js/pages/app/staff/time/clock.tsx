import { clockTime, duration, FLAG_LABELS, formatDay, Hours, number, ShiftStatusPill, shopDay } from '@/components/app/staff-time/format';
import { TimeFilters, TimePageLayout } from '@/components/app/staff-time/time-page';
import { type ClockProps, type ClockRow } from '@/components/app/staff-time/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { type ColumnDef } from '@tanstack/react-table';
import { Clock, Coffee, TimerOff, TriangleAlert, UserCheck } from 'lucide-react';

const columns: ColumnDef<ClockRow>[] = [
    {
        id: 'person',
        header: 'Person',
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-medium">{row.original.person}</span>
                <span className="text-muted-foreground text-xs">
                    {[row.original.shop, row.original.till].filter(Boolean).join(' · ') || 'Unknown shop'}
                </span>
            </div>
        ),
    },
    {
        id: 'day',
        header: 'Day',
        meta: { mobile: 'field' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span>{formatDay(row.original.day)}</span>
                {row.original.planned && <span className="text-muted-foreground text-xs">Rota {row.original.planned}</span>}
            </div>
        ),
    },
    {
        id: 'times',
        header: 'In and out',
        meta: { mobile: 'field', label: 'In and out' },
        cell: ({ row }) => {
            const r = row.original;
            const overnight = r.clockIn && r.clockOut && shopDay(r.clockIn) !== shopDay(r.clockOut);

            return (
                <div className="grid gap-0.5 text-sm leading-5 tabular-nums">
                    <span>
                        {clockTime(r.clockIn)} – {clockTime(r.clockOut)}
                        {overnight && <span className="text-muted-foreground text-xs"> (next day)</span>}
                    </span>
                    {r.breaks.map((b, i) => (
                        <span key={i} className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                            <Coffee className="size-3" aria-hidden />
                            Break {clockTime(b.start)} – {b.end ? clockTime(b.end) : 'not ended'}
                        </span>
                    ))}
                </div>
            );
        },
    },
    {
        id: 'status',
        header: 'Status',
        meta: { mobile: 'aside' },
        cell: ({ row }) => (
            <div className="grid justify-items-start gap-1">
                <ShiftStatusPill status={row.original.status} />
                {row.original.flags.map((f) => (
                    <span key={f} className="text-warning-foreground inline-flex items-center gap-1 text-xs font-medium">
                        <TriangleAlert className="size-3.5" aria-hidden />
                        {FLAG_LABELS[f]}
                    </span>
                ))}
            </div>
        ),
    },
    {
        id: 'breaks',
        header: 'Breaks',
        meta: { align: 'right', mobile: 'hidden' },
        cell: ({ row }) => (
            <span className="text-muted-foreground tabular-nums">{row.original.breakMinutes ? duration(row.original.breakMinutes) : '—'}</span>
        ),
    },
    {
        id: 'worked',
        header: 'Worked',
        meta: { align: 'right', mobile: 'aside' },
        cell: ({ row }) =>
            row.original.status === 'complete' ? (
                <Hours minutes={row.original.workedMinutes} />
            ) : (
                <span className="text-muted-foreground text-sm">Not counted</span>
            ),
    },
];

export default function StaffClock({ shifts, summary, filters, options }: ClockProps) {
    const { update, loading } = useTableQuery({ only: ['shifts', 'summary', 'filters', 'options'] });

    return (
        <TimePageLayout
            tab="clock"
            filters={filters}
            title="Clock events"
            description="Every clock in, clock out and break pressed on the tills, paired into shifts. Read only: fix a missed clock on the till."
        >
            <StatGrid>
                <StatCard label="Shifts worked" value={number(summary.shifts)} hint="Clocked in and out" icon={Clock} tone="primary" />
                <StatCard
                    label="Hours worked"
                    value={duration(summary.workedMinutes)}
                    hint={`${(summary.workedMinutes / 60).toFixed(2)} hours, breaks taken off`}
                    icon={UserCheck}
                    tone="success"
                />
                <StatCard
                    label="Missing clocks"
                    value={number(summary.missing)}
                    hint="No clock-out or no clock-in"
                    icon={TimerOff}
                    tone={summary.missing > 0 ? 'danger' : 'neutral'}
                />
                <StatCard label="On shift now" value={number(summary.onShift)} icon={UserCheck} tone="neutral" />
            </StatGrid>
            {summary.missing > 0 && (
                <Alert variant="destructive">
                    <TriangleAlert />
                    <AlertDescription>
                        {summary.missing === 1 ? 'One shift has' : `${summary.missing} shifts have`} a missing clock. A shift with no clock-out after{' '}
                        {summary.openLimitHours} hours, or followed by another clock-in, is not counted in timesheets until it is corrected on the
                        till.
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
                    <TimeFilters filters={filters} options={options} update={update} showTill>
                        <div className="flex h-9 items-center gap-2">
                            <Checkbox
                                id="problems"
                                checked={filters.problems}
                                onCheckedChange={(on) => update({ problems: on === true ? '1' : undefined, page: undefined })}
                            />
                            <Label htmlFor="problems" className="text-sm font-normal">
                                Problems only ({number(summary.problems)})
                            </Label>
                        </div>
                    </TimeFilters>
                }
                getRowId={(row) => row.id}
                empty={
                    <EmptyState
                        icon={Clock}
                        title={filters.problems ? 'No problems' : 'No clock events'}
                        body={
                            filters.problems
                                ? 'Every shift in these dates was clocked in and out properly.'
                                : 'Nobody clocked in on a till in these dates. Staff clock in on the till when "Clock in" is turned on in till settings.'
                        }
                    />
                }
            />
        </TimePageLayout>
    );
}

import { Money } from '@/components/app/cash/format';
import { Difference, duration, formatDay, Hours, money, number, ROUNDING_OPTIONS } from '@/components/app/staff-time/format';
import { TimeFilters, TimePageLayout } from '@/components/app/staff-time/time-page';
import { type TimesheetRow, type TimesheetsProps } from '@/components/app/staff-time/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type ColumnDef } from '@tanstack/react-table';
import { CalendarClock, Clock, Download, PoundSterling, TimerOff, TriangleAlert } from 'lucide-react';
import { type FormEvent, useState } from 'react';

function columns(weekly: boolean, overtime: boolean): ColumnDef<TimesheetRow>[] {
    return [
        {
            id: 'person',
            header: 'Person',
            meta: { mobile: 'title' },
            cell: ({ row }) => (
                <div className="grid leading-5">
                    <span className="font-medium">{row.original.person}</span>
                    <span className="text-muted-foreground text-xs">
                        {[row.original.shop ?? 'Unknown shop', weekly && row.original.weekStart ? `w/c ${formatDay(row.original.weekStart)}` : null]
                            .filter(Boolean)
                            .join(' · ')}
                    </span>
                </div>
            ),
        },
        { id: 'shifts', header: 'Shifts', meta: { align: 'right', mobile: 'hidden' }, cell: ({ row }) => number(row.original.shifts) },
        {
            id: 'worked',
            header: 'Worked',
            meta: { align: 'right', mobile: 'field' },
            cell: ({ row }) => (
                <div className="grid justify-items-end gap-0.5">
                    <Hours minutes={row.original.paidMinutes} />
                    {row.original.paidMinutes !== row.original.workedMinutes && (
                        <span className="text-muted-foreground text-xs">exact {duration(row.original.workedMinutes)}</span>
                    )}
                </div>
            ),
        },
        {
            id: 'breaks',
            header: 'Breaks',
            meta: { align: 'right', mobile: 'hidden' },
            cell: ({ row }) => <span className="text-muted-foreground tabular-nums">{duration(row.original.breakMinutes)}</span>,
        },
        ...(overtime
            ? [
                  {
                      id: 'overtime',
                      header: 'Overtime',
                      meta: { align: 'right' as const, mobile: 'field' as const },
                      cell: ({ row }: { row: { original: TimesheetRow } }) =>
                          row.original.overtimeMinutes ? <Hours minutes={row.original.overtimeMinutes} /> : <span className="text-muted-foreground">—</span>,
                  },
              ]
            : []),
        {
            id: 'planned',
            header: 'Rota',
            meta: { align: 'right', mobile: 'hidden' },
            cell: ({ row }) => (
                <div className="grid justify-items-end gap-0.5 tabular-nums">
                    <span>{duration(row.original.plannedMinutes)}</span>
                    {row.original.plannedMinutes > 0 && <Difference minutes={row.original.differenceMinutes} />}
                </div>
            ),
        },
        {
            id: 'wage',
            header: 'Wage estimate',
            meta: { align: 'right', mobile: 'aside' },
            cell: ({ row }) =>
                row.original.rate ? (
                    <div className="grid justify-items-end leading-5">
                        <Money value={row.original.wage} className="font-medium" />
                        <span className="text-muted-foreground text-xs">at {money(row.original.rate)}/h</span>
                    </div>
                ) : (
                    <span className="text-muted-foreground text-xs">No hourly rate</span>
                ),
        },
        {
            id: 'status',
            header: 'Status',
            meta: { mobile: 'field' },
            cell: ({ row }) => (
                <div className="grid justify-items-start gap-1">
                    {row.original.approval ? (
                        <StatusPill tone="success">Approved on till · {row.original.approval.totalHours} h</StatusPill>
                    ) : (
                        weekly && <StatusPill tone="neutral">Not approved</StatusPill>
                    )}
                    {row.original.missing > 0 && (
                        <span className="text-danger-foreground inline-flex items-center gap-1 text-xs font-medium">
                            <TriangleAlert className="size-3.5" aria-hidden />
                            {row.original.missing} missing clock{row.original.missing === 1 ? '' : 's'}
                        </span>
                    )}
                </div>
            ),
        },
    ];
}

function OvertimeForm({ value, update }: { value: string | null; update: (p: { overtime?: string; page?: undefined }) => void }) {
    const [draft, setDraft] = useState(value ?? '');
    const submit = (e: FormEvent) => {
        e.preventDefault();
        update({ overtime: draft.trim() || undefined, page: undefined });
    };

    return (
        <form onSubmit={submit} className="flex items-center gap-1.5">
            <Input
                inputMode="decimal"
                className="h-9 w-full sm:w-36"
                aria-label="Overtime after hours a week"
                placeholder="Overtime after… h/wk"
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
            />
            <Button type="submit" variant="outline" size="sm" className="h-9">
                Apply
            </Button>
        </form>
    );
}

export default function StaffTimesheets({ timesheets, summary, wageBands, filters, options }: TimesheetsProps) {
    const { update, loading } = useTableQuery({ only: ['timesheets', 'summary', 'wageBands', 'filters', 'options'] });
    const weekly = filters.group === 'week';
    const exportQuery = {
        from: filters.from,
        to: filters.to,
        group: filters.group,
        ...(filters.shopLocked ? {} : { shop: filters.shop ?? 'all' }),
        ...(filters.person ? { person: filters.person } : {}),
        ...(filters.rounding ? { rounding: String(filters.rounding) } : {}),
        ...(filters.overtime ? { overtime: filters.overtime } : {}),
    };
    const rule = [
        filters.rounding ? `Each shift rounded to the nearest ${filters.rounding} minutes.` : 'Exact minutes, no rounding.',
        filters.overtime ? `Overtime is time over ${filters.overtime} hours in a Monday–Sunday week.` : 'No overtime rule chosen (the till has none).',
    ].join(' ');

    return (
        <TimePageLayout
            tab="timesheets"
            filters={filters}
            title="Timesheets"
            description={`Hours worked per person and shop from the tills' clock events, against the rota. ${rule}`}
            actions={
                <Button variant="outline" asChild>
                    <a href={route('app.staff.time.export', exportQuery)}>
                        <Download />
                        Payroll CSV
                    </a>
                </Button>
            }
        >
            <StatGrid>
                <StatCard label="Paid hours" value={duration(summary.paidMinutes)} hint={`${number(summary.people)} people`} icon={Clock} tone="primary" />
                <StatCard
                    label="Rota hours"
                    value={duration(summary.plannedMinutes)}
                    hint={`Worked ${summary.paidMinutes >= summary.plannedMinutes ? '+' : '−'}${duration(Math.abs(summary.paidMinutes - summary.plannedMinutes))} against the rota`}
                    icon={CalendarClock}
                    tone="neutral"
                />
                <StatCard
                    label="Wage estimate"
                    value={money(summary.wages)}
                    hint={summary.withoutRate > 0 ? `${summary.withoutRate} with no hourly rate left out` : 'At each person’s hourly rate'}
                    icon={PoundSterling}
                    tone={summary.withoutRate > 0 ? 'warning' : 'success'}
                />
                <StatCard
                    label={filters.overtime ? 'Overtime' : 'Missing clocks'}
                    value={filters.overtime ? duration(summary.overtimeMinutes) : number(summary.missing)}
                    hint={filters.overtime ? `${number(summary.missing)} missing clocks not counted` : 'Not counted until fixed on the till'}
                    icon={TimerOff}
                    tone={summary.missing > 0 ? 'danger' : 'neutral'}
                />
            </StatGrid>
            <DataTable
                columns={columns(weekly, filters.overtime !== null)}
                data={timesheets.data}
                meta={timesheets.meta}
                onChange={update}
                loading={loading}
                filters={
                    <TimeFilters filters={filters} options={options} update={update}>
                        <Select value={filters.group} onValueChange={(group) => update({ group: group === 'week' ? undefined : group, page: undefined })}>
                            <SelectTrigger className="h-9 w-full sm:w-36" aria-label="Group by">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="week">Per week</SelectItem>
                                <SelectItem value="period">Whole period</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            value={String(filters.rounding)}
                            onValueChange={(r) => update({ rounding: r === '0' ? undefined : r, page: undefined })}
                        >
                            <SelectTrigger className="h-9 w-full sm:w-40" aria-label="Rounding">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {ROUNDING_OPTIONS.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <OvertimeForm key={filters.overtime ?? ''} value={filters.overtime} update={update} />
                    </TimeFilters>
                }
                getRowId={(row) => row.id}
                empty={
                    <EmptyState
                        icon={Clock}
                        title="No hours in these dates"
                        body={`Nobody clocked in or was on the rota between ${formatDay(filters.from)} and ${formatDay(filters.to)}.`}
                    />
                }
            />
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <SectionCard
                    title="How wages are estimated"
                    description="An estimate for payroll, not a payslip."
                    contentClassName="text-muted-foreground grid gap-2 text-sm"
                >
                    <p>Paid hours × the hourly rate on the person's staff record (Staff → edit → Hourly rate). Overtime is paid at the same rate: the till has no overtime premium.</p>
                    <p>Breaks are unpaid. Shifts with a missing clock-out are left out until corrected on the till. Holidays and absence are not recorded by the till yet.</p>
                    <p>"Approved on till" shows the hours a manager approved for that week on the till.</p>
                </SectionCard>
                <SectionCard title="Minimum wage bands" description="The age bands the tills hold, for checking rates." flush>
                    {wageBands.length === 0 ? (
                        <EmptyState size="sm" icon={PoundSterling} title="No wage bands yet" body="They arrive with the tills' next sync." />
                    ) : (
                        <ul className="divide-y text-sm">
                            {wageBands.map((b) => (
                                <li key={b.id} className="flex items-center justify-between gap-3 px-5 py-2.5 sm:px-6">
                                    <span className="grid leading-5">
                                        <span className="font-medium">{b.label || `Age ${b.ageFrom}${b.ageTo ? `–${b.ageTo}` : '+'}`}</span>
                                        <span className="text-muted-foreground text-xs">
                                            Age {b.ageFrom}
                                            {b.ageTo ? `–${b.ageTo}` : '+'} · from {formatDay(b.effectiveFrom)}
                                            {b.isPlaceholder ? ' · till default' : ''}
                                        </span>
                                    </span>
                                    <span className="font-medium tabular-nums">{money(b.rate)}/h</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </TimePageLayout>
    );
}

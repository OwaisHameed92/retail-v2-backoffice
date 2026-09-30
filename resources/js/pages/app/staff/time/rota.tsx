import { Difference, duration, formatDay, number, shortDay } from '@/components/app/staff-time/format';
import { addDays, mondayOf, TimeFilters, TimePageLayout } from '@/components/app/staff-time/time-page';
import { type RotaDay, type RotaProps } from '@/components/app/staff-time/types';
import { useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { CalendarClock, CalendarDays, ChevronLeft, ChevronRight, Clock, Info, TimerOff, Users } from 'lucide-react';

function londonToday(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/London' }).format(new Date());
}

function DayCell({ cell, today }: { cell: RotaDay | null; today: boolean }) {
    if (!cell) {
        return <span className="text-muted-foreground/60 text-xs">Off</span>;
    }

    return (
        <div className={cn('grid gap-1 text-xs leading-4', today && 'font-medium')}>
            {cell.planned.map((p) => (
                <span
                    key={p.id}
                    className={cn(
                        'grid rounded-md border px-1.5 py-1',
                        p.isPublished ? 'border-primary/30 bg-primary/5' : 'border-dashed bg-transparent',
                    )}
                    title={p.note || undefined}
                >
                    <span className="tabular-nums">
                        {p.start ?? '?'}–{p.end ?? '?'}
                    </span>
                    <span className="text-muted-foreground">
                        {p.readable ? duration(p.minutes) : 'Time unreadable'}
                        {p.breakMinutes ? ` · ${p.breakMinutes}m break` : ''}
                        {p.isPublished ? '' : ' · draft'}
                        {p.shop ? ` · ${p.shop}` : ''}
                    </span>
                </span>
            ))}
            {(cell.workedMinutes > 0 || cell.onShift || cell.missing > 0) && (
                <span className="text-muted-foreground inline-flex flex-wrap items-center gap-1">
                    <Clock className="size-3" aria-hidden />
                    {cell.workedMinutes > 0 && <span className="text-foreground tabular-nums">Worked {duration(cell.workedMinutes)}</span>}
                    {cell.onShift && <span className="text-info-foreground">On shift</span>}
                    {cell.missing > 0 && <span className="text-danger-foreground">No clock-out</span>}
                </span>
            )}
        </div>
    );
}

export default function StaffRota({ week, days, rows, summary, filters, options }: RotaProps) {
    const { update, loading } = useTableQuery({ only: ['week', 'days', 'rows', 'summary', 'filters', 'options'] });
    const today = londonToday();
    const thisWeek = mondayOf(today);

    return (
        <TimePageLayout
            tab="rota"
            filters={filters}
            title="Rota"
            description="Each person's planned shifts for the week beside the hours they clocked. Rotas are made on the till."
        >
            <Alert>
                <Info />
                <AlertDescription>
                    Rotas belong to the shop: they are planned and published on the till and sent here, so this page is read only. Holidays and
                    absence are not recorded by the till yet.
                </AlertDescription>
            </Alert>
            <StatGrid>
                <StatCard label="People on the rota" value={number(summary.people)} icon={Users} tone="primary" />
                <StatCard
                    label="Planned hours"
                    value={duration(summary.plannedMinutes)}
                    hint={`${number(summary.shifts)} shifts${summary.drafts ? `, ${summary.drafts} not published` : ''}`}
                    icon={CalendarClock}
                    tone="neutral"
                />
                <StatCard label="Worked hours" value={duration(summary.workedMinutes)} hint="Clocked in and out" icon={Clock} tone="success" />
                <StatCard
                    label="Worked against plan"
                    value={`${summary.workedMinutes >= summary.plannedMinutes ? '+' : '−'}${duration(Math.abs(summary.workedMinutes - summary.plannedMinutes))}`}
                    hint={summary.unreadable ? `${summary.unreadable} shifts with unreadable times` : 'Worked minus planned'}
                    icon={TimerOff}
                    tone={summary.unreadable ? 'warning' : 'neutral'}
                />
            </StatGrid>
            <SectionCard
                title={`Week of ${formatDay(week)}`}
                description={`${shortDay(days[0])} to ${shortDay(days[6])}`}
                actions={
                    <div className="flex items-center gap-1.5">
                        <Button variant="outline" size="icon" aria-label="Previous week" disabled={loading} onClick={() => update({ week: addDays(week, -7) })}>
                            <ChevronLeft />
                        </Button>
                        <Button variant="outline" size="sm" disabled={loading || week === thisWeek} onClick={() => update({ week: thisWeek })}>
                            This week
                        </Button>
                        <Button variant="outline" size="icon" aria-label="Next week" disabled={loading} onClick={() => update({ week: addDays(week, 7) })}>
                            <ChevronRight />
                        </Button>
                    </div>
                }
                flush
            >
                <div className="border-b px-5 py-3 sm:px-6">
                    <TimeFilters filters={filters} options={options} update={update} dates={false} />
                </div>
                {rows.length === 0 ? (
                    <EmptyState
                        icon={CalendarDays}
                        title="Nobody on the rota this week"
                        body="No rota shifts or clock events for this week. Plan the rota on the till and it appears here after the next sync."
                    />
                ) : (
                    <div className={cn('overflow-x-auto', loading && 'opacity-60')}>
                        <table className="w-full min-w-[960px] text-sm">
                            <thead className="bg-subtle text-muted-foreground text-xs">
                                <tr>
                                    <th className="px-4 py-2.5 text-left font-medium">Person</th>
                                    {days.map((d) => (
                                        <th key={d} className={cn('px-2 py-2.5 text-left font-medium', d === today && 'text-foreground')}>
                                            {shortDay(d)}
                                            {d === today && (
                                                <StatusPill tone="info" className="ml-1">
                                                    Today
                                                </StatusPill>
                                            )}
                                        </th>
                                    ))}
                                    <th className="px-4 py-2.5 text-right font-medium">Planned</th>
                                    <th className="px-4 py-2.5 text-right font-medium">Worked</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.map((r) => (
                                    <tr key={r.personId} className="align-top">
                                        <td className="px-4 py-3 font-medium">{r.person}</td>
                                        {days.map((d) => (
                                            <td key={d} className={cn('w-[11%] px-2 py-3', d === today && 'bg-primary/[0.03]')}>
                                                <DayCell cell={r.days[d] ?? null} today={d === today} />
                                            </td>
                                        ))}
                                        <td className="px-4 py-3 text-right tabular-nums">{duration(r.plannedMinutes)}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            <div className="grid justify-items-end">
                                                <span>{duration(r.workedMinutes)}</span>
                                                {r.plannedMinutes > 0 && <Difference minutes={r.differenceMinutes} />}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </SectionCard>
        </TimePageLayout>
    );
}

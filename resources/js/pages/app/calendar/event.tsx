import { eventDates, EventStatusPill, KIND_LABELS, longDay } from '@/components/app/calendar/calendar-page';
import { type EventProps } from '@/components/app/calendar/types';
import { ChartCard, SegmentedControl } from '@/components/shared/chart-card';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { CompareChart } from '@/components/shared/trading/compare-chart';
import { changeDelta, money, moneyAxis, number, shortDay } from '@/components/shared/trading/format';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { BarChart3, Info, PoundSterling, Receipt, ShoppingBasket, Wallet } from 'lucide-react';

function Change({ value }: { value: string | null }) {
    if (value === null) {
        return <span className="text-muted-foreground">—</span>;
    }
    const n = Number(value);

    return (
        <span className={n > 0 ? 'text-success-foreground' : n < 0 ? 'text-danger-foreground' : 'text-muted-foreground'}>
            {n > 0 ? '+' : n < 0 ? '−' : ''}
            {Math.abs(n).toLocaleString('en-GB', { maximumFractionDigits: 1 })}%
        </span>
    );
}

/** Module 5.9: one seasonal event's sales against last year's (rpt_* figures). */
export default function CalendarEvent(props: EventProps) {
    const { event, everyShop, canCompareEveryShop, basis, lastYear, thisYear, daysCompared, totals, days, departments } = props;
    const upcoming = event.status === 'upcoming';
    const lastYearLabel = lastYear.name ? `${lastYear.name} last year` : 'Same dates last year';
    const delta = (change: string | null) => (upcoming ? undefined : changeDelta(change, 'vs last year'));
    const hint = (value: string | null, format: (v: string | null) => string) => `Last year ${format(value)}`;
    const chart = days.map((d) => ({
        label: `Day ${d.day}`,
        title: `Day ${d.day} · ${shortDay(d.date)}`,
        compareTitle: `Last year · ${shortDay(d.lastYearDate)}`,
        current: d.gross === null ? null : Number(d.gross),
        compare: Number(d.lastYearGross),
    }));

    return (
        <AppLayout>
            <Head title={`${event.name} · Seasonal events`} />
            <PageHeader
                title={event.name}
                status={<EventStatusPill status={event.status} />}
                back={{ href: route('app.calendar.events'), label: 'Seasonal events' }}
                description={[eventDates(event.startsOn, event.endsOn), event.kind ? KIND_LABELS[event.kind] : null, event.shop].filter(Boolean).join(' · ')}
                actions={
                    canCompareEveryShop && (
                        <SegmentedControl
                            label="Which shops"
                            value={everyShop ? 'all' : 'shop'}
                            options={[
                                { value: 'shop', label: event.shop ?? 'This shop' },
                                { value: 'all', label: 'Every shop' },
                            ]}
                            onChange={(value) => router.get(route('app.calendar.events.show', event.id), value === 'all' ? { shops: 'all' } : {}, { preserveScroll: true })}
                        />
                    )
                }
            />

            <div className="grid gap-6">
                <Alert variant="info">
                    <Info />
                    <AlertDescription>
                        {basis === 'lastYearEvent'
                            ? `Compared with ${lastYear.name ?? 'the same event'} last year (${eventDates(lastYear.from, lastYear.eventTo)}), day for day.`
                            : `The till has no ${event.name} last year, so this compares the same dates a year earlier (${eventDates(lastYear.from, lastYear.to)}).`}{' '}
                        {event.status === 'onNow' &&
                            `The event is on now: the first ${daysCompared} ${daysCompared === 1 ? 'day is' : 'days are'} compared (to ${longDay(thisYear.to)}).`}
                        {upcoming && 'The event has not started yet: last year’s figures show what to plan for.'}
                    </AlertDescription>
                </Alert>

                <StatGrid>
                    <StatCard
                        label={upcoming ? 'Sales last year' : 'Sales (inc VAT)'}
                        value={money(upcoming ? totals.gross.previous : totals.gross.current)}
                        delta={delta(totals.gross.change)}
                        hint={upcoming ? lastYearLabel : hint(totals.gross.previous, money)}
                        icon={PoundSterling}
                        tone="primary"
                    />
                    <StatCard
                        label={upcoming ? 'Net sales last year' : 'Net sales'}
                        value={money(upcoming ? totals.net.previous : totals.net.current)}
                        delta={delta(totals.net.change)}
                        hint={upcoming ? 'Excluding VAT' : hint(totals.net.previous, money)}
                        icon={Wallet}
                        tone="success"
                    />
                    <StatCard
                        label={upcoming ? 'Transactions last year' : 'Transactions'}
                        value={number(upcoming ? totals.transactions.previous : totals.transactions.current)}
                        delta={delta(totals.transactions.change)}
                        hint={upcoming ? undefined : `Last year ${number(totals.transactions.previous)}`}
                        icon={Receipt}
                        tone="neutral"
                    />
                    <StatCard
                        label={upcoming ? 'Average basket last year' : 'Average basket'}
                        value={money(upcoming ? totals.basket.previous : totals.basket.current)}
                        delta={delta(totals.basket.change)}
                        hint={upcoming ? 'Including VAT' : hint(totals.basket.previous, money)}
                        icon={ShoppingBasket}
                        tone="neutral"
                    />
                </StatGrid>

                <ChartCard icon={BarChart3} title="Sales day by day" subtitle={`Including VAT · ${event.name} against ${lastYearLabel.toLowerCase()}`}>
                    <CompareChart data={chart} variant="bar" format={money} axisFormat={moneyAxis} currentName="This year" compareName="Last year" />
                </ChartCard>

                <SectionCard
                    title="Departments"
                    description="Net sales (ex VAT) by department, with the uplift the till plans for this event (learned from past years or set on the till)."
                    flush
                >
                    {departments.length === 0 ? (
                        <EmptyState icon={BarChart3} size="sm" title="No department sales" body="No product sales in either window yet." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Department</TableHead>
                                    <TableHead className="text-right">This year</TableHead>
                                    <TableHead className="text-right">Last year</TableHead>
                                    <TableHead className="text-right">Change</TableHead>
                                    <TableHead className="hidden text-right md:table-cell">Till&apos;s uplift</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {departments.map((d) => (
                                    <TableRow key={d.id ?? 'none'}>
                                        <TableCell className="font-medium">{d.name}</TableCell>
                                        <TableCell className="text-right tabular-nums">{upcoming ? '—' : money(d.net)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{money(d.lastYearNet)}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <Change value={d.change} />
                                        </TableCell>
                                        <TableCell className="text-muted-foreground hidden text-right tabular-nums md:table-cell">
                                            {d.tillUplift === null ? '—' : `${Number(d.tillUplift) > 0 ? '+' : ''}${d.tillUplift}%${d.learned ? ' (learned)' : ''}`}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </SectionCard>
            </div>
        </AppLayout>
    );
}

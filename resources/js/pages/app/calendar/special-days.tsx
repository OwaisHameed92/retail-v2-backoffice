import { CalendarFilterBar, CalendarPageLayout, hoursText, longDay } from '@/components/app/calendar/calendar-page';
import { type SpecialDaysProps } from '@/components/app/calendar/types';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { CalendarX2 } from 'lucide-react';

/** Module 5.9: the tills' special days (read only: each shop sets them on its till). */
export default function CalendarSpecialDays({ days, filters, shops, total, limit }: SpecialDaysProps) {
    const showShop = !filters.shopLocked && filters.shop === null && shops.length > 1;

    return (
        <CalendarPageLayout
            tab="special"
            filters={filters}
            title="Special days"
            description="Bank holidays, closures and changed hours, as each shop's till holds them. Change them on the till: they arrive here after it syncs."
        >
            <SectionCard
                title={filters.when === 'past' ? 'Past special days' : filters.when === 'all' ? 'All special days' : 'Upcoming special days'}
                description={total > limit ? `Showing the first ${limit} of ${total}.` : undefined}
                actions={<CalendarFilterBar filters={filters} shops={shops} route="app.calendar.special-days" />}
                flush
            >
                {days.length === 0 ? (
                    <EmptyState
                        icon={CalendarX2}
                        size="sm"
                        title="No special days"
                        body="When a till marks a bank holiday, a closure or different hours for a day, it shows here."
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Day</TableHead>
                                {showShop && <TableHead>Shop</TableHead>}
                                <TableHead>Opening</TableHead>
                                <TableHead className="hidden md:table-cell">Alcohol sales</TableHead>
                                <TableHead className="hidden lg:table-cell">Notes</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {days.map((d) => (
                                <TableRow key={d.id}>
                                    <TableCell>
                                        <div className="grid leading-5">
                                            <span className="font-medium">{longDay(d.date)}</span>
                                            {d.event && <span className="text-muted-foreground text-xs">{d.event}</span>}
                                        </div>
                                    </TableCell>
                                    {showShop && <TableCell>{d.shop ?? 'Unknown shop'}</TableCell>}
                                    <TableCell>
                                        {d.closed ? (
                                            <StatusPill tone="warning">Closed</StatusPill>
                                        ) : (
                                            <span className="tabular-nums">{d.opens ? hoursText(d.opens, d.closes) : 'Usual hours'}</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground hidden tabular-nums md:table-cell">
                                        {d.licensedOpens ? hoursText(d.licensedOpens, d.licensedCloses) : 'Usual hours'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground hidden max-w-xs truncate lg:table-cell">{d.notes ?? '—'}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </SectionCard>
        </CalendarPageLayout>
    );
}

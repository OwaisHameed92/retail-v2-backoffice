import { CalendarFilterBar, CalendarPageLayout, eventDates, eventKindLabel, EventStatusPill } from '@/components/app/calendar/calendar-page';
import { type EventsProps } from '@/components/app/calendar/types';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Link } from '@inertiajs/react';
import { ChevronRight, PartyPopper } from 'lucide-react';

/** Module 5.9: seasonal events from the tills (read only), each with a comparison against last year's. */
export default function CalendarEvents({ events, filters, shops, total, limit }: EventsProps) {
    const showShop = !filters.shopLocked && filters.shop === null && shops.length > 1;

    return (
        <CalendarPageLayout
            tab="events"
            filters={filters}
            title="Seasonal events"
            description="Christmas, Ramadan, Easter, bank and school holidays and local events, as each shop's till plans for them. Open one to compare its sales with last year's."
        >
            <SectionCard
                title={filters.when === 'past' ? 'Past events' : filters.when === 'all' ? 'All events' : 'On now and coming up'}
                description={total > limit ? `Showing the first ${limit} of ${total}.` : 'Events are set on each till; they arrive here after it syncs.'}
                actions={<CalendarFilterBar filters={filters} shops={shops} route="app.calendar.events" />}
                flush
            >
                {events.length === 0 ? (
                    <EmptyState
                        icon={PartyPopper}
                        size="sm"
                        title="No seasonal events"
                        body="Seasonal events the tills know about (for stock planning and promotions) appear here after they sync."
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Event</TableHead>
                                <TableHead>Dates</TableHead>
                                {showShop && <TableHead className="hidden md:table-cell">Shop</TableHead>}
                                <TableHead className="hidden lg:table-cell">Department uplifts</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-8" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {events.map((e) => (
                                <TableRow key={e.id} className="group">
                                    <TableCell>
                                        <Link href={route('app.calendar.events.show', e.id)} className="grid leading-5">
                                            <span className="font-medium group-hover:underline">{e.name}</span>
                                            <span className="text-muted-foreground text-xs">
                                                {[e.kind ? eventKindLabel(e.kind) : null, e.nation, e.isActive ? null : 'Turned off'].filter(Boolean).join(' · ')}
                                            </span>
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <div className="grid leading-5">
                                            <span>{eventDates(e.startsOn, e.endsOn)}</span>
                                            <span className="text-muted-foreground text-xs">
                                                {e.days} {e.days === 1 ? 'day' : 'days'}
                                            </span>
                                        </div>
                                    </TableCell>
                                    {showShop && <TableCell className="hidden md:table-cell">{e.shop ?? 'Unknown shop'}</TableCell>}
                                    <TableCell className="text-muted-foreground hidden lg:table-cell">{e.uplifts ? `${e.uplifts} departments` : 'None'}</TableCell>
                                    <TableCell>
                                        <EventStatusPill status={e.status} />
                                    </TableCell>
                                    <TableCell>
                                        <Link href={route('app.calendar.events.show', e.id)} aria-label={`Compare ${e.name} with last year`}>
                                            <ChevronRight className="text-muted-foreground size-4" />
                                        </Link>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </SectionCard>
        </CalendarPageLayout>
    );
}

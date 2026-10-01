import { CalendarPageLayout, hoursText, longDay } from '@/components/app/calendar/calendar-page';
import { HoursDialog } from '@/components/app/calendar/hours-dialog';
import { type HoursProps, type ShopHours } from '@/components/app/calendar/types';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Link } from '@inertiajs/react';
import { CalendarClock, Info, Store, TriangleAlert } from 'lucide-react';

function ShopCard({ shop, defaults, canApplyToEveryShop }: { shop: ShopHours; defaults: HoursProps['defaults']; canApplyToEveryShop: boolean }) {
    return (
        <SectionCard
            title={shop.name}
            description={
                shop.days
                    ? 'Used for this shop’s till alerts. The tills show one line of opening hours for the whole business.'
                    : `Not set. Till alerts use ${defaults.opens}–${defaults.closes} every day.`
            }
            actions={<HoursDialog shop={shop} defaults={defaults} canApplyToEveryShop={canApplyToEveryShop} />}
        >
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                {shop.days ? (
                    <dl className="divide-border grid divide-y text-sm">
                        {shop.days.map((day) => (
                            <div key={day.weekday} className="flex items-center justify-between gap-4 py-2">
                                <dt className="font-medium">{day.name}</dt>
                                <dd className={day.closed ? 'text-muted-foreground' : 'tabular-nums'}>
                                    {day.closed ? 'Closed' : hoursText(day.opens, day.closes)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                ) : (
                    <EmptyState
                        icon={CalendarClock}
                        size="sm"
                        title="No opening hours yet"
                        body="Set this shop's week so the tills show the right hours and alerts only fire when the shop is open."
                    />
                )}
                <div className="grid content-start gap-4">
                    <div>
                        <h3 className="text-sm font-semibold">Next special days</h3>
                        {shop.specialDays.length === 0 ? (
                            <p className="text-muted-foreground mt-1 text-sm">None on the till. Bank holidays and closures are set on the till.</p>
                        ) : (
                            <ul className="mt-2 grid gap-1.5 text-sm">
                                {shop.specialDays.map((d) => (
                                    <li key={d.id} className="flex flex-wrap items-center justify-between gap-2">
                                        <span>
                                            {longDay(d.date)}
                                            {d.event && <span className="text-muted-foreground"> · {d.event}</span>}
                                        </span>
                                        {d.closed ? (
                                            <StatusPill tone="warning">Closed</StatusPill>
                                        ) : (
                                            <span className="tabular-nums">{d.opens ? hoursText(d.opens, d.closes) : 'Usual hours'}</span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Link href={route('app.calendar.special-days')} className="text-primary mt-2 inline-block text-sm font-medium hover:underline">
                            All special days
                        </Link>
                    </div>
                    {shop.days && !shop.tillTextMatches && (
                        <Alert variant="warning">
                            <TriangleAlert />
                            <AlertDescription>
                                The tills&apos; opening hours line was changed on a till{shop.tillText ? ` (“${shop.tillText.slice(0, 60)}…”)` : ''}. Save
                                the hours here again to send the line back.
                            </AlertDescription>
                        </Alert>
                    )}
                </div>
            </div>
        </SectionCard>
    );
}

/** Module 5.9: each shop's weekly opening hours, sent to its tills and used by till health. */
export default function CalendarHours({ shops, defaults, businessLine, filters }: HoursProps) {
    return (
        <CalendarPageLayout
            tab="hours"
            filters={filters}
            title="Opening hours"
            description="Opening hours for each shop, bank holidays and closures from the tills, and seasonal events against last year."
        >
            <Alert variant="info">
                <Info />
                <AlertDescription>
                    Till alerts only count time when each shop is open. The tills show one &quot;Opening hours&quot; line for the whole business on the
                    customer screen (up to {businessLine.max} characters, shown as written)
                    {businessLine.text ? (
                        <>
                            , taken from {businessLine.shopName}: <span className="font-medium">{businessLine.text}</span>
                        </>
                    ) : null}
                    . Special days (bank holidays, closures, late openings) are kept on each till and shown here.
                </AlertDescription>
            </Alert>
            {businessLine.differs && (
                <Alert variant="warning">
                    <TriangleAlert />
                    <AlertDescription>
                        Your shops&apos; weekly hours differ, but tills show one line for the business, so every till shows {businessLine.shopName}&apos;s
                        hours. Each shop&apos;s own week is still used for its till alerts.
                    </AlertDescription>
                </Alert>
            )}
            {shops.length === 0 ? (
                <EmptyState icon={Store} title="No shops yet" body="Shops appear here once they are set up." />
            ) : (
                shops.map((shop) => <ShopCard key={shop.id} shop={shop} defaults={defaults} canApplyToEveryShop={!filters.shopLocked && shops.length > 1} />)
            )}
        </CalendarPageLayout>
    );
}

import { FilterSelect } from '@/components/app/setup/fields';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { type ReactNode } from 'react';
import { type CalendarFilters, type EventKind, type EventStatus, type ShopOption } from './types';

export type CalendarTab = 'hours' | 'special' | 'events';

const TABS: { key: CalendarTab; label: string; route: string }[] = [
    { key: 'hours', label: 'Opening hours', route: 'app.calendar.hours' },
    { key: 'special', label: 'Special days', route: 'app.calendar.special-days' },
    { key: 'events', label: 'Seasonal events', route: 'app.calendar.events' },
];

const dayFormat = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
const shortFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });

/** "Fri 25 Dec 2026" from "2026-12-25". */
export function longDay(day: string): string {
    return dayFormat.format(new Date(`${day}T00:00:00Z`));
}

/** "20 Dec – 26 Dec 2026", or one day. */
export function eventDates(from: string, to: string): string {
    return from === to ? longDay(from) : `${shortFormat.format(new Date(`${from}T00:00:00Z`))} – ${longDay(to)}`;
}

/** "07:00 – 22:00", with "(next day)" when the shop closes after midnight; "Open 24 hours" for the same time. */
export function hoursText(opens: string | null, closes: string | null): string {
    if (!opens || !closes) {
        return 'Closed';
    }
    if (opens === closes) {
        return 'Open 24 hours';
    }

    return `${opens} – ${closes}${closes < opens ? ' (next day)' : ''}`;
}

export const KIND_LABELS: Record<EventKind, string> = {
    fixed: 'Fixed date',
    moveable: 'Moveable',
    bankHoliday: 'Bank holiday',
    schoolHoliday: 'School holiday',
    local: 'Local event',
};

const STATUS: Record<EventStatus, { label: string; tone: StatusTone }> = {
    onNow: { label: 'On now', tone: 'success' },
    upcoming: { label: 'Upcoming', tone: 'info' },
    past: { label: 'Past', tone: 'neutral' },
};

export function EventStatusPill({ status }: { status: EventStatus }) {
    return <StatusPill tone={STATUS[status].tone}>{STATUS[status].label}</StatusPill>;
}

/** Shop (pinned for a one-shop user) and upcoming / past / all, for the till lists. */
export function CalendarFilterBar({ filters, shops, route: name }: { filters: CalendarFilters; shops: ShopOption[]; route: string }) {
    const go = (params: Record<string, string | undefined>) =>
        router.get(
            route(name),
            { shop: filters.shopLocked ? undefined : (filters.shop ?? 'all'), when: filters.when, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <div className="flex w-full flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <Select value={filters.when} onValueChange={(when) => go({ when })}>
                <SelectTrigger className="h-9 w-full sm:w-40" aria-label="Which dates">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="upcoming">Upcoming</SelectItem>
                    <SelectItem value="past">Past</SelectItem>
                    <SelectItem value="all">All dates</SelectItem>
                </SelectContent>
            </Select>
            {filters.shopLocked ? (
                <StatusPill tone="neutral" className="h-9 gap-1.5 px-3">
                    <Lock className="size-3.5" />
                    {shops[0]?.label ?? 'Your shop'}
                </StatusPill>
            ) : (
                shops.length > 1 && (
                    <FilterSelect
                        value={filters.shop}
                        onChange={(shop) => go({ shop: shop ?? 'all' })}
                        all="Every shop"
                        options={shops}
                        label="Filter by shop"
                    />
                )
            )}
        </div>
    );
}

interface CalendarPageLayoutProps {
    tab: CalendarTab;
    filters: CalendarFilters;
    title: string;
    description: ReactNode;
    children: ReactNode;
}

/** The Calendar frame: header, section tabs, then the page. */
export function CalendarPageLayout({ tab, filters, title, description, children }: CalendarPageLayoutProps) {
    const keep = filters.shopLocked ? {} : { shop: filters.shop ?? 'all' };

    return (
        <AppLayout>
            <Head title={`${title} · Calendar`} />
            <PageHeader
                title="Calendar"
                description={description}
                tabs={
                    <PageTabs
                        label="Calendar sections"
                        tabs={TABS.map((t) => ({ label: t.label, href: route(t.route, t.key === 'hours' ? {} : keep), active: t.key === tab }))}
                    />
                }
            />
            <div className="grid gap-6">{children}</div>
        </AppLayout>
    );
}

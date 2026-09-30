import { FilterSelect } from '@/components/app/setup/fields';
import { type TableParams } from '@/components/shared/data-table';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusPill } from '@/components/shared/status-badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { type ReactNode } from 'react';
import { type TimeFiltersState, type TimePageProps } from './types';

export type TimeTab = 'clock' | 'timesheets' | 'rota';

const TABS: { key: TimeTab; label: string; route: string }[] = [
    { key: 'clock', label: 'Clock events', route: 'app.staff.time.clock' },
    { key: 'timesheets', label: 'Timesheets', route: 'app.staff.time.timesheets' },
    { key: 'rota', label: 'Rota', route: 'app.staff.time.rota' },
];

/** What every tab keeps: dates, shop, person and the pay rules. */
function keep(filters: TimeFiltersState): Record<string, string> {
    return {
        from: filters.from,
        to: filters.to,
        ...(filters.shopLocked ? {} : { shop: filters.shop ?? 'all' }),
        ...(filters.person ? { person: filters.person } : {}),
        ...(filters.rounding ? { rounding: String(filters.rounding) } : {}),
        ...(filters.overtime ? { overtime: filters.overtime } : {}),
    };
}

function londonToday(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/London' }).format(new Date());
}

export function addDays(day: string, days: number): string {
    const d = new Date(`${day}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);

    return d.toISOString().slice(0, 10);
}

export function mondayOf(day: string): string {
    const weekday = new Date(`${day}T12:00:00Z`).getUTCDay();

    return addDays(day, -((weekday + 6) % 7));
}

function monthRange(offset: number): { from: string; to: string } {
    const [y, m] = londonToday().split('-').map(Number);
    const first = new Date(Date.UTC(y, m - 1 + offset, 1, 12));
    const last = new Date(Date.UTC(y, m + offset, 0, 12));

    return { from: first.toISOString().slice(0, 10), to: last.toISOString().slice(0, 10) };
}

const PRESETS: { value: string; label: string; range: () => { from: string; to: string } }[] = [
    { value: 'week', label: 'This week', range: () => ({ from: mondayOf(londonToday()), to: addDays(mondayOf(londonToday()), 6) }) },
    {
        value: 'lastWeek',
        label: 'Last week',
        range: () => ({ from: addDays(mondayOf(londonToday()), -7), to: addDays(mondayOf(londonToday()), -1) }),
    },
    {
        value: 'twoWeeks',
        label: 'Last 2 weeks',
        range: () => ({ from: addDays(mondayOf(londonToday()), -14), to: addDays(mondayOf(londonToday()), -1) }),
    },
    { value: 'month', label: 'This month', range: () => monthRange(0) },
    { value: 'lastMonth', label: 'Last month', range: () => monthRange(-1) },
];

interface FiltersProps extends TimePageProps {
    update: (params: TableParams) => void;
    dates?: boolean;
    showTill?: boolean;
    children?: ReactNode;
}

/** Dates (London days), shop (pinned for a one-shop user), till and person. */
export function TimeFilters({ filters, options, update, dates = true, showTill = false, children }: FiltersProps) {
    const preset = PRESETS.find((p) => {
        const r = p.range();
        return r.from === filters.from && r.to === filters.to;
    });
    const reset = { page: undefined };

    return (
        <div className="flex w-full flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            {dates && (
                <>
                    <Select
                        value={preset?.value ?? 'custom'}
                        onValueChange={(value) => {
                            const p = PRESETS.find((x) => x.value === value);
                            if (p) update({ ...p.range(), ...reset });
                        }}
                    >
                        <SelectTrigger className="h-9 w-full sm:w-36" aria-label="Date range">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PRESETS.map((p) => (
                                <SelectItem key={p.value} value={p.value}>
                                    {p.label}
                                </SelectItem>
                            ))}
                            <SelectItem value="custom">Custom dates</SelectItem>
                        </SelectContent>
                    </Select>
                    <div className="flex items-center gap-1.5">
                        <Input
                            type="date"
                            className="h-9 w-full sm:w-38"
                            aria-label="From"
                            value={filters.from}
                            max={filters.to}
                            onChange={(e) => e.target.value && update({ from: e.target.value, ...reset })}
                        />
                        <span className="text-muted-foreground text-sm">to</span>
                        <Input
                            type="date"
                            className="h-9 w-full sm:w-38"
                            aria-label="To"
                            value={filters.to}
                            min={filters.from}
                            onChange={(e) => e.target.value && update({ to: e.target.value, ...reset })}
                        />
                    </div>
                </>
            )}
            {filters.shopLocked ? (
                <StatusPill tone="neutral" className="h-9 gap-1.5 px-3">
                    <Lock className="size-3.5" />
                    {options.shops[0]?.label ?? 'Your shop'}
                </StatusPill>
            ) : (
                options.shops.length > 1 && (
                    <FilterSelect
                        value={filters.shop}
                        onChange={(shop) => update({ shop: shop ?? 'all', till: undefined, ...reset })}
                        all="Every shop"
                        options={options.shops}
                        label="Filter by shop"
                    />
                )
            )}
            {showTill && options.tills.length > 1 && (
                <FilterSelect
                    value={filters.till}
                    onChange={(till) => update({ till, ...reset })}
                    all="Every till"
                    options={options.tills}
                    label="Filter by till"
                />
            )}
            {options.people.length > 0 && (
                <FilterSelect
                    value={filters.person}
                    onChange={(person) => update({ person, ...reset })}
                    all="Everyone"
                    options={options.people}
                    label="Filter by person"
                />
            )}
            {children}
        </div>
    );
}

interface TimePageLayoutProps {
    tab: TimeTab;
    filters: TimeFiltersState;
    title: string;
    description: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
}

/** The Staff time frame: header, the section tabs, then the page. */
export function TimePageLayout({ tab, filters, title, description, actions, children }: TimePageLayoutProps) {
    return (
        <AppLayout>
            <Head title={`${title} · Staff time`} />
            <PageHeader
                title="Staff time"
                description={description}
                actions={actions}
                tabs={
                    <PageTabs
                        label="Staff time sections"
                        tabs={TABS.map((t) => ({ label: t.label, href: route(t.route, keep(filters)), active: t.key === tab }))}
                    />
                }
            />
            <div className="grid gap-6">{children}</div>
        </AppLayout>
    );
}

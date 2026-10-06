import { FilterSelect } from '@/components/app/setup/fields';
import { type TableParams } from '@/components/shared/data-table';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { zonedDateFormat } from '@/lib/country';
import { Head, Link } from '@inertiajs/react';
import { BarChart3, Lock } from 'lucide-react';
import { type ReactNode } from 'react';
import { type CashFiltersState, type CashPageProps } from './types';

export type CashTab = 'shifts' | 'z' | 'banking' | 'counts' | 'cards' | 'days' | 'alerts';

const TABS: { key: CashTab; label: string; route: string }[] = [
    { key: 'shifts', label: 'Shifts', route: 'app.cash.index' },
    { key: 'z', label: 'Z reports', route: 'app.cash.z.index' },
    { key: 'banking', label: 'Banking', route: 'app.cash.banking' },
    { key: 'counts', label: 'Safe counts', route: 'app.cash.counts' },
    { key: 'cards', label: 'Card settlements', route: 'app.cash.cards' },
    { key: 'days', label: 'Day locks', route: 'app.cash.days' },
    { key: 'alerts', label: 'Variance alerts', route: 'app.cash.alerts' },
];

/** The filters every tab keeps (dates and shop); till and tab-only filters are dropped when switching. */
function keep(filters: CashFiltersState): Record<string, string> {
    return { from: filters.from, to: filters.to, ...(filters.shopLocked ? {} : { shop: filters.shop ?? 'all' }) };
}

function shopToday(): string {
    return zonedDateFormat('en-CA').format(new Date());
}

function addDays(day: string, days: number): string {
    const d = new Date(`${day}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);

    return d.toISOString().slice(0, 10);
}

const PRESETS: { value: string; label: string; range: () => { from: string; to: string } }[] = [
    { value: 'today', label: 'Today', range: () => ({ from: shopToday(), to: shopToday() }) },
    { value: 'yesterday', label: 'Yesterday', range: () => ({ from: addDays(shopToday(), -1), to: addDays(shopToday(), -1) }) },
    { value: '7', label: 'Last 7 days', range: () => ({ from: addDays(shopToday(), -6), to: shopToday() }) },
    { value: '30', label: 'Last 30 days', range: () => ({ from: addDays(shopToday(), -29), to: shopToday() }) },
    { value: 'month', label: 'This month', range: () => ({ from: `${shopToday().slice(0, 8)}01`, to: shopToday() }) },
];

interface FiltersProps extends CashPageProps {
    update: (params: TableParams) => void;
    showTill?: boolean;
    children?: ReactNode;
}

/** Dates (presets or custom, shop trading days), shop (pinned for a one-shop user) and till. */
export function CashFilters({ filters, options, update, showTill = true, children }: FiltersProps) {
    const preset = PRESETS.find((p) => {
        const r = p.range();
        return r.from === filters.from && r.to === filters.to;
    });
    const reset = { page: undefined };

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-1 sm:flex-row sm:flex-wrap sm:items-center">
            <Select
                value={preset?.value ?? 'custom'}
                onValueChange={(value) => {
                    const p = PRESETS.find((x) => x.value === value);
                    if (p) update({ ...p.range(), ...reset });
                }}
            >
                <SelectTrigger className="h-9 w-full sm:w-40" aria-label="Date range">
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
                    aria-label="From trading day"
                    value={filters.from}
                    max={filters.to}
                    onChange={(e) => e.target.value && update({ from: e.target.value, ...reset })}
                />
                <span className="text-muted-foreground text-sm">to</span>
                <Input
                    type="date"
                    className="h-9 w-full sm:w-38"
                    aria-label="To trading day"
                    value={filters.to}
                    min={filters.from}
                    onChange={(e) => e.target.value && update({ to: e.target.value, ...reset })}
                />
            </div>
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
            {children}
        </div>
    );
}

interface CashPageLayoutProps {
    tab: CashTab;
    filters: CashFiltersState;
    title?: string;
    description: string;
    children: ReactNode;
}

/** The Cash and Z frame: header with the link to the Shifts and Z report (4.8), the section tabs, then the page. */
export function CashPageLayout({ tab, filters, title, description, children }: CashPageLayoutProps) {
    const reportQuery = { period: 'custom', from: filters.from, to: filters.to, ...(filters.till ? { till: filters.till } : {}) };

    return (
        <AppLayout>
            <Head title={title ?? 'Cash and Z'} />
            <PageHeader
                title="Cash and Z"
                description={description}
                actions={
                    <Button variant="outline" asChild>
                        <Link href={route('app.reports.show', { report: 'shifts', ...reportQuery })}>
                            <BarChart3 />
                            Shifts and Z report
                        </Link>
                    </Button>
                }
                tabs={
                    <PageTabs
                        label="Cash and Z sections"
                        tabs={TABS.map((t) => ({ label: t.label, href: route(t.route, keep(filters)), active: t.key === tab }))}
                    />
                }
            />
            <div className="grid gap-6">{children}</div>
        </AppLayout>
    );
}

import { FilterSelect } from '@/components/app/setup/fields';
import { type TableParams } from '@/components/shared/data-table';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusPill } from '@/components/shared/status-badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { zonedDateFormat } from '@/lib/country';
import { Head } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { type ReactNode } from 'react';
import { type ComplianceFiltersState, type CompliancePageProps } from './types';

export type ComplianceTab = 'overview' | 'age' | 'incidents' | 'training' | 'diary' | 'licences' | 'recalls' | 'exceptions';

const TABS: { key: ComplianceTab; label: string; route: string; dated: boolean }[] = [
    { key: 'overview', label: 'Overview', route: 'app.compliance.index', dated: false },
    { key: 'age', label: 'Age checks', route: 'app.compliance.age-checks', dated: true },
    { key: 'incidents', label: 'Incidents', route: 'app.compliance.incidents', dated: true },
    { key: 'training', label: 'Training', route: 'app.compliance.training', dated: false },
    { key: 'diary', label: 'Diary checks', route: 'app.compliance.diary', dated: true },
    { key: 'licences', label: 'Licences', route: 'app.compliance.licences', dated: false },
    { key: 'recalls', label: 'Recalls', route: 'app.compliance.recalls', dated: false },
    { key: 'exceptions', label: 'Exceptions', route: 'app.compliance.exceptions', dated: true },
];

/** The filters every tab keeps (dates and shop); tab-only filters are dropped when switching. */
function keep(filters: ComplianceFiltersState): Record<string, string> {
    return { from: filters.from, to: filters.to, ...(filters.shopLocked ? {} : { shop: filters.shop ?? 'all' }) };
}

function shopOnly(filters: ComplianceFiltersState): Record<string, string> {
    return filters.shopLocked ? {} : { shop: filters.shop ?? 'all' };
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
    { value: '7', label: 'Last 7 days', range: () => ({ from: addDays(shopToday(), -6), to: shopToday() }) },
    { value: '30', label: 'Last 30 days', range: () => ({ from: addDays(shopToday(), -29), to: shopToday() }) },
    { value: '90', label: 'Last 90 days', range: () => ({ from: addDays(shopToday(), -89), to: shopToday() }) },
    { value: 'month', label: 'This month', range: () => ({ from: `${shopToday().slice(0, 8)}01`, to: shopToday() }) },
    { value: 'year', label: 'Last 12 months', range: () => ({ from: addDays(shopToday(), -365), to: shopToday() }) },
];

interface FiltersProps extends Pick<CompliancePageProps, 'filters' | 'options'> {
    update: (params: TableParams) => void;
    dates?: boolean;
    staff?: boolean;
    children?: ReactNode;
}

/** Dates (presets or custom, shop days), shop (pinned for a one-shop user), staff member and page-specific filters. */
export function ComplianceFilters({ filters, options, update, dates = true, staff = true, children }: FiltersProps) {
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
                        onChange={(shop) => update({ shop: shop ?? 'all', ...reset })}
                        all="Every shop"
                        options={options.shops}
                        label="Filter by shop"
                    />
                )
            )}
            {staff && options.staff.length > 0 && (
                <FilterSelect
                    value={filters.staff}
                    onChange={(s) => update({ staff: s, ...reset })}
                    all="All staff"
                    options={options.staff}
                    label="Filter by staff"
                />
            )}
            {children}
        </div>
    );
}

interface LayoutProps {
    tab: ComplianceTab;
    filters: ComplianceFiltersState;
    title: string;
    description: string;
    actions?: ReactNode;
    children: ReactNode;
}

/** The Compliance frame: header, the section tabs (dates and shop carried over), then the page. */
export function CompliancePageLayout({ tab, filters, title, description, actions, children }: LayoutProps) {
    return (
        <AppLayout>
            <Head title={`${title} · Compliance`} />
            <PageHeader
                title="Compliance"
                description={description}
                actions={actions}
                tabs={
                    <PageTabs
                        label="Compliance sections"
                        tabs={TABS.map((t) => ({
                            label: t.label,
                            href: route(t.route, t.dated ? keep(filters) : shopOnly(filters)),
                            active: t.key === tab,
                        }))}
                    />
                }
            />
            <div className="grid gap-6">{children}</div>
        </AppLayout>
    );
}

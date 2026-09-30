import { type Option, type TradingCompare, type TradingContext, type TradingFilters, type TradingPeriod } from '@/components/admin/trading/types';
import { BusinessPicker } from '@/components/admin/licences/licence-filters';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Building2, CalendarDays, ChevronDown, GitCompareArrows, Store, X } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';

/** Query parameters of `admin.trading`; defaults (today, previous period) are left out of the URL. */
export type TradingQuery = Partial<Record<'period' | 'from' | 'to' | 'compare' | 'company' | 'branch', string>>;

export function queryOf(filters: TradingFilters, next: Partial<TradingFilters> = {}): TradingQuery {
    const f = { ...filters, ...next };
    const query: TradingQuery = {};

    if (f.period !== 'today') {
        query.period = f.period;
    }
    if (f.period === 'custom') {
        query.from = f.from;
        query.to = f.to;
    }
    if (f.compare !== 'previousPeriod') {
        query.compare = f.compare;
    }
    if (f.company) {
        query.company = f.company;
    }
    if (f.company && f.branch) {
        query.branch = f.branch;
    }

    return query;
}

interface TradingFiltersBarProps {
    filters: TradingFilters;
    context: TradingContext;
    periods: Option<TradingPeriod>[];
    compares: Option<TradingCompare>[];
    onChange: (query: TradingQuery) => void;
}

/**
 * The trading dashboard's filters (DASHBOARD.md §2.1): date range preset or custom days, compare-to, and the
 * drill-down (business picker, then one of its shops). Every change is a URL change, so a view can be shared.
 */
export function TradingFiltersBar({ filters, context, periods, compares, onChange }: TradingFiltersBarProps) {
    const [picking, setPicking] = useState(false);
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const custom = filters.period === 'custom';

    useEffect(() => {
        setFrom(filters.from);
        setTo(filters.to);
    }, [filters.from, filters.to]);

    const applyCustom = (event: FormEvent) => {
        event.preventDefault();
        if (from && to) {
            onChange(queryOf(filters, { period: 'custom', from, to }));
        }
    };

    const choosePeriod = (period: TradingPeriod) => {
        onChange(queryOf(filters, { period, from: filters.from, to: filters.to }));
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <Select value={filters.period} onValueChange={(value) => choosePeriod(value as TradingPeriod)}>
                    <SelectTrigger className="bg-card h-9 w-full sm:w-44" aria-label="Date range">
                        <CalendarDays className="text-muted-foreground size-4" aria-hidden />
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {periods.map((period) => (
                            <SelectItem key={period.value} value={period.value}>
                                {period.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select value={filters.compare} onValueChange={(value) => onChange(queryOf(filters, { compare: value as TradingCompare }))}>
                    <SelectTrigger className="bg-card h-9 w-full sm:w-56" aria-label="Compare to">
                        <GitCompareArrows className="text-muted-foreground size-4" aria-hidden />
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {compares.map((compare) => (
                            <SelectItem key={compare.value} value={compare.value}>
                                {compare.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <div className="flex w-full items-center gap-1 sm:w-auto">
                    <Button variant="outline" className="h-9 min-w-0 flex-1 justify-start sm:max-w-64 sm:flex-none" onClick={() => setPicking(true)}>
                        <Building2 className="text-muted-foreground" aria-hidden />
                        <span className="truncate">{context.company?.name ?? 'All businesses'}</span>
                        <ChevronDown className="text-muted-foreground ml-auto" aria-hidden />
                    </Button>
                    {context.company && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-9 shrink-0"
                            aria-label="Show all businesses"
                            onClick={() => onChange(queryOf(filters, { company: null, branch: null }))}
                        >
                            <X />
                        </Button>
                    )}
                </div>

                {context.company && context.branches.length > 0 && (
                    <Select
                        value={filters.branch ?? 'all'}
                        onValueChange={(value) => onChange(queryOf(filters, { branch: value === 'all' ? null : value }))}
                    >
                        <SelectTrigger className="bg-card h-9 w-full sm:w-52" aria-label="Shop">
                            <Store className="text-muted-foreground size-4" aria-hidden />
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All shops</SelectItem>
                            {context.branches.map((branch) => (
                                <SelectItem key={branch.id} value={branch.id}>
                                    {branch.name}
                                    {!branch.active && <span className="text-muted-foreground ml-1">(closed)</span>}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </div>

            {custom && (
                <form onSubmit={applyCustom} className="flex flex-wrap items-end gap-2" aria-label="Custom date range">
                    <label className="grid gap-1 text-xs font-medium">
                        <span className="text-muted-foreground">From</span>
                        <Input type="date" value={from} max={to || filters.today} onChange={(e) => setFrom(e.target.value)} className="h-9 w-40" required />
                    </label>
                    <label className="grid gap-1 text-xs font-medium">
                        <span className="text-muted-foreground">To</span>
                        <Input type="date" value={to} min={from} max={filters.today} onChange={(e) => setTo(e.target.value)} className="h-9 w-40" required />
                    </label>
                    <Button type="submit" variant="outline" className="h-9" disabled={!from || !to || (from === filters.from && to === filters.to)}>
                        Apply
                    </Button>
                    <p className="text-muted-foreground w-full text-xs sm:w-auto">Up to 366 days, ending today at the latest.</p>
                </form>
            )}

            <BusinessPicker
                open={picking}
                onOpenChange={setPicking}
                title="Show one business"
                description="See the trading of one customer and drill into its shops."
                onPick={(id) => {
                    setPicking(false);
                    onChange(queryOf(filters, { company: id, branch: null }));
                }}
            />
        </div>
    );
}

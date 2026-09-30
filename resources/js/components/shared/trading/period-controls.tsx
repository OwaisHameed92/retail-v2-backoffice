import { type Option, type PeriodFilters, type TradingCompare, type TradingPeriod } from '@/components/shared/trading/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { CalendarDays, GitCompareArrows } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';

/** The date range preset (DASHBOARD.md §2.1). */
export function PeriodSelect({ value, periods, onChange }: { value: TradingPeriod; periods: Option<TradingPeriod>[]; onChange: (period: TradingPeriod) => void }) {
    return (
        <Select value={value} onValueChange={(next) => onChange(next as TradingPeriod)}>
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
    );
}

/** "Compare to" (DASHBOARD.md §2.9). */
export function CompareSelect({ value, compares, onChange }: { value: TradingCompare; compares: Option<TradingCompare>[]; onChange: (compare: TradingCompare) => void }) {
    return (
        <Select value={value} onValueChange={(next) => onChange(next as TradingCompare)}>
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
    );
}

/** From / To days of a custom range, applied together (the server clamps: up to 366 days, ending today at the latest). */
export function CustomRangeForm({ filters, onApply }: { filters: PeriodFilters; onApply: (from: string, to: string) => void }) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

    useEffect(() => {
        setFrom(filters.from);
        setTo(filters.to);
    }, [filters.from, filters.to]);

    const apply = (event: FormEvent) => {
        event.preventDefault();
        if (from && to) {
            onApply(from, to);
        }
    };

    return (
        <form onSubmit={apply} className="flex flex-wrap items-end gap-2" aria-label="Custom date range">
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
    );
}

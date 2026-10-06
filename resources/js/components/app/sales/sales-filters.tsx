import { FilterSelect } from '@/components/app/setup/fields';
import { type TableParams } from '@/components/shared/data-table';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { zonedDateFormat } from '@/lib/country';
import { Lock, SlidersHorizontal, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { type SaleFiltersState, type SalesIndexProps } from './types';

/** Today in shop time as `Y-m-d` (the shops' trading day). */
function shopToday(): string {
    return zonedDateFormat('en-CA').format(new Date());
}

function addDays(day: string, days: number): string {
    const d = new Date(`${day}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);

    return d.toISOString().slice(0, 10);
}

const PRESETS = [
    { value: 'today', label: 'Today' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: '7', label: 'Last 7 days' },
    { value: '30', label: 'Last 30 days' },
    { value: 'month', label: 'This month' },
    { value: 'lastMonth', label: 'Last month' },
];

function presetRange(preset: string): { from: string; to: string } {
    const today = shopToday();
    switch (preset) {
        case 'today':
            return { from: today, to: today };
        case 'yesterday':
            return { from: addDays(today, -1), to: addDays(today, -1) };
        case '30':
            return { from: addDays(today, -29), to: today };
        case 'month':
            return { from: `${today.slice(0, 8)}01`, to: today };
        case 'lastMonth': {
            const end = addDays(`${today.slice(0, 8)}01`, -1);
            return { from: `${end.slice(0, 8)}01`, to: end };
        }
        default:
            return { from: addDays(today, -6), to: today };
    }
}

function currentPreset(filters: SaleFiltersState): string {
    return (
        PRESETS.find((p) => {
            const r = presetRange(p.value);
            return r.from === filters.from && r.to === filters.to;
        })?.value ?? 'custom'
    );
}

interface Props {
    filters: SaleFiltersState;
    options: SalesIndexProps['options'];
    update: (params: TableParams) => void;
}

/** Dates, shop, till, staff and tender in the toolbar; amount and customer behind "More filters". */
export function SalesFilters({ filters, options, update }: Props) {
    const extra = [filters.min, filters.max, filters.customer].filter(Boolean).length;
    const [open, setOpen] = useState(extra > 0);
    const reset = { after: undefined, before: undefined };
    const preset = currentPreset(filters);

    return (
        <div className="flex w-full flex-col gap-2">
            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <Select value={preset} onValueChange={(value) => value !== 'custom' && update({ ...presetRange(value), ...reset })}>
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
                            value={filters.shop === 'all' ? null : filters.shop}
                            onChange={(shop) => update({ shop: shop ?? 'all', till: undefined, ...reset })}
                            all="Every shop"
                            options={options.shops}
                            label="Filter by shop"
                        />
                    )
                )}
                <FilterSelect
                    value={filters.till}
                    onChange={(till) => update({ till, ...reset })}
                    all="Every till"
                    options={options.tills}
                    label="Filter by till"
                />
                <FilterSelect
                    value={filters.staff}
                    onChange={(staff) => update({ staff, ...reset })}
                    all="All staff"
                    options={options.staff}
                    label="Filter by staff member"
                />
                <FilterSelect
                    value={filters.payment}
                    onChange={(payment) => update({ payment, ...reset })}
                    all="Any payment"
                    options={options.payments}
                    label="Filter by payment type"
                />
                <Button variant="outline" size="sm" className="h-9" onClick={() => setOpen((v) => !v)} aria-expanded={open}>
                    <SlidersHorizontal />
                    More filters
                    {extra > 0 && (
                        <span className="bg-primary-soft text-accent-foreground rounded-full px-1.5 text-[11px] tabular-nums">{extra}</span>
                    )}
                </Button>
            </div>
            {open && <MoreFilters filters={filters} update={(p) => update({ ...p, ...reset })} />}
        </div>
    );
}

function MoreFilters({ filters, update }: { filters: SaleFiltersState; update: (params: TableParams) => void }) {
    const [min, setMin] = useState(filters.min ?? '');
    const [max, setMax] = useState(filters.max ?? '');
    const [customer, setCustomer] = useState(filters.customer ?? '');
    const isId = /^[0-9A-Za-z]{26}$/.test(customer);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        update({ min: min.trim() || undefined, max: max.trim() || undefined, customer: customer.trim() || undefined });
    };

    const clear = () => {
        setMin('');
        setMax('');
        setCustomer('');
        update({ min: undefined, max: undefined, customer: undefined });
    };

    return (
        <form
            onSubmit={submit}
            className="bg-subtle grid grid-cols-1 gap-3 rounded-lg border p-3 sm:grid-cols-[repeat(3,minmax(0,1fr))_auto] sm:items-end"
        >
            <div className="grid gap-1.5">
                <Label htmlFor="sales-min">Amount from (£)</Label>
                <Input id="sales-min" inputMode="decimal" placeholder="0.00" value={min} onChange={(e) => setMin(e.target.value)} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="sales-max">Amount to (£)</Label>
                <Input id="sales-max" inputMode="decimal" placeholder="Any" value={max} onChange={(e) => setMax(e.target.value)} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="sales-customer">Customer</Label>
                <Input
                    id="sales-customer"
                    placeholder={isId ? 'The customer you came from' : 'Name, card number or phone'}
                    value={isId ? '' : customer}
                    onChange={(e) => setCustomer(e.target.value)}
                />
            </div>
            <div className="flex gap-2">
                <Button type="submit" size="sm" className="h-9">
                    Apply
                </Button>
                <Button type="button" variant="ghost" size="sm" className="h-9" onClick={clear}>
                    <X />
                    Clear
                </Button>
            </div>
            <p className="text-muted-foreground text-xs sm:col-span-4">Amounts match refunds too (a £5 refund counts as £5).</p>
        </form>
    );
}

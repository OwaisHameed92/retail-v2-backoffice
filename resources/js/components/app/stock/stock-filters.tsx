import { FilterSelect } from '@/components/app/setup/fields';
import { type TableParams } from '@/components/shared/data-table';
import { StatusPill } from '@/components/shared/status-badge';
import { Input } from '@/components/ui/input';
import { Lock } from 'lucide-react';
import { type Option, type StockFiltersState, type StockOptions } from './types';

interface Props {
    filters: StockFiltersState;
    options: StockOptions;
    update: (params: TableParams) => void;
    /** Which filters this screen offers besides the shop. */
    show?: { department?: boolean; supplier?: boolean; dates?: boolean; types?: Option[] };
}

const reset = { after: undefined, before: undefined, page: undefined };

/** The shop (locked for a one-shop user), then department, supplier, movement kind and dates where the screen has them. */
export function StockFilters({ filters, options, update, show = {} }: Props) {
    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-1 sm:flex-row sm:flex-wrap sm:items-center">
            {filters.shopLocked ? (
                <StatusPill tone="neutral" className="h-9 gap-1.5 px-3">
                    <Lock className="size-3.5" />
                    {options.shops[0]?.label ?? 'Your shop'}
                </StatusPill>
            ) : (
                options.shops.length > 1 && (
                    <FilterSelect
                        value={filters.shop === 'all' ? null : filters.shop}
                        onChange={(shop) => update({ shop: shop ?? 'all', ...reset })}
                        all="Every shop"
                        options={options.shops}
                        label="Shop"
                    />
                )
            )}
            {show.department && options.departments.length > 0 && (
                <FilterSelect
                    value={filters.department}
                    onChange={(department) => update({ department, ...reset })}
                    all="Every department"
                    options={options.departments}
                    label="Department"
                />
            )}
            {show.supplier && options.suppliers.length > 0 && (
                <FilterSelect
                    value={filters.supplier}
                    onChange={(supplier) => update({ supplier, ...reset })}
                    all="Every supplier"
                    options={options.suppliers}
                    label="Supplier"
                />
            )}
            {show.types && (
                <FilterSelect
                    value={filters.type}
                    onChange={(type) => update({ type, ...reset })}
                    all="Every kind"
                    options={show.types}
                    label="Kind"
                />
            )}
            {show.dates && (
                <div className="flex items-center gap-1.5">
                    <Input
                        type="date"
                        className="h-9 w-full sm:w-38"
                        aria-label="From day"
                        value={filters.from}
                        max={filters.to}
                        onChange={(e) => e.target.value && update({ from: e.target.value, ...reset })}
                    />
                    <span className="text-muted-foreground text-sm">to</span>
                    <Input
                        type="date"
                        className="h-9 w-full sm:w-38"
                        aria-label="To day"
                        value={filters.to}
                        min={filters.from}
                        onChange={(e) => e.target.value && update({ to: e.target.value, ...reset })}
                    />
                </div>
            )}
        </div>
    );
}

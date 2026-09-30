import { type BusinessContext, type BusinessFilters } from '@/components/app/dashboard/types';
import { type BranchSharedData } from '@/components/app-branch-switcher';
import { CompareSelect, CustomRangeForm, PeriodSelect } from '@/components/shared/trading/period-controls';
import { type Option, type TradingCompare, type TradingPeriod } from '@/components/shared/trading/types';
import { Select, SelectContent, SelectItem, SelectSeparator, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router, usePage } from '@inertiajs/react';
import { Lock, Monitor, Store } from 'lucide-react';

/** Query parameters of `app.dashboard`; defaults (today, previous period, every till) stay out of the URL. */
export type BusinessQuery = Partial<Record<'period' | 'from' | 'to' | 'compare' | 'till', string>>;

export function businessQuery(filters: BusinessFilters, next: Partial<BusinessFilters> = {}): BusinessQuery {
    const f = { ...filters, ...next };
    const query: BusinessQuery = {};

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
    if (f.till) {
        query.till = f.till;
    }

    return query;
}

interface BusinessFiltersBarProps {
    filters: BusinessFilters;
    context: BusinessContext;
    periods: Option<TradingPeriod>[];
    compares: Option<TradingCompare>[];
    onChange: (query: BusinessQuery) => void;
    onLoading: (loading: boolean) => void;
}

const ALL = 'all';

/**
 * The business dashboard's filters (DASHBOARD.md §2.1): date range, compare-to, shop and till. The shop is the
 * portal's branch switcher (changing it here changes it everywhere); a one-shop user sees their shop, locked. The
 * till (one of the chosen shop's) and the dates are in the URL, so a view can be shared.
 */
export function BusinessFiltersBar({ filters, context, periods, compares, onChange, onLoading }: BusinessFiltersBarProps) {
    const { branches } = usePage<BranchSharedData>().props;

    const chooseShop = (value: string) => {
        router.post(
            route('app.branch.switch'),
            { branch_id: value === ALL ? null : value },
            { preserveScroll: true, onStart: () => onLoading(true), onFinish: () => onLoading(false) },
        );
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <PeriodSelect value={filters.period} periods={periods} onChange={(period) => onChange(businessQuery(filters, { period }))} />
                <CompareSelect value={filters.compare} compares={compares} onChange={(compare) => onChange(businessQuery(filters, { compare }))} />

                {context.restricted ? (
                    <span className="bg-card text-foreground inline-flex h-9 w-full items-center gap-2 rounded-md border px-3 text-sm sm:w-auto" title="Your account is limited to this shop">
                        <Store className="text-muted-foreground size-4" aria-hidden />
                        <span className="truncate">{context.branch?.name ?? 'Your shop'}</span>
                        <Lock className="text-muted-foreground ml-auto size-3.5 sm:ml-1" aria-label="Your shop only" />
                    </span>
                ) : (
                    <Select value={context.branch?.id ?? ALL} onValueChange={chooseShop} disabled={!branches || branches.length === 0}>
                        <SelectTrigger className="bg-card h-9 w-full sm:w-52" aria-label="Shop">
                            <Store className="text-muted-foreground size-4" aria-hidden />
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All shops</SelectItem>
                            {branches && branches.length > 0 && <SelectSeparator />}
                            {branches?.map((branch) => (
                                <SelectItem key={branch.id} value={branch.id}>
                                    {branch.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}

                {context.branch && context.tills.length > 1 && (
                    <Select value={filters.till ?? ALL} onValueChange={(value) => onChange(businessQuery(filters, { till: value === ALL ? null : value }))}>
                        <SelectTrigger className="bg-card h-9 w-full sm:w-52" aria-label="Till">
                            <Monitor className="text-muted-foreground size-4" aria-hidden />
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All tills</SelectItem>
                            <SelectSeparator />
                            {context.tills.map((till) => (
                                <SelectItem key={till.id} value={till.id}>
                                    {till.code} – {till.name}
                                    {!till.active && <span className="text-muted-foreground ml-1">(closed)</span>}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </div>

            {filters.period === 'custom' && <CustomRangeForm filters={filters} onApply={(from, to) => onChange(businessQuery(filters, { period: 'custom', from, to }))} />}
        </div>
    );
}

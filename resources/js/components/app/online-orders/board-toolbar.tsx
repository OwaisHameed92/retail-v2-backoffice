import { FilterSelect } from '@/components/app/setup/fields';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Lock } from 'lucide-react';
import { useState } from 'react';
import { type BoardFilters } from './types';

/** "2026-11-02" ± days, as a calendar day (no time zone involved). */
function shiftDay(day: string, by: number): string {
    const date = new Date(`${day}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + by);

    return date.toISOString().slice(0, 10);
}

/** The board's filters: the day (previous / today / next and a date picker) and the shop (when there is more than one). */
export function BoardFiltersBar({ filters, shops }: { filters: BoardFilters; shops: { value: string; label: string }[] }) {
    const visit = (next: Partial<{ day: string; shop: string }>) =>
        router.get(
            route('app.online-orders.index'),
            { day: filters.day === filters.today ? undefined : filters.day, shop: filters.shop ?? 'all', ...next },
            { preserveScroll: true, preserveState: true, replace: true },
        );

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-1 sm:flex-row sm:flex-wrap sm:items-center">
            <div className="flex items-center gap-1.5">
                <Button
                    variant="outline"
                    size="icon"
                    className="size-9"
                    aria-label="Day before"
                    onClick={() => visit({ day: shiftDay(filters.day, -1) })}
                >
                    <ChevronLeft className="size-4" aria-hidden />
                </Button>
                <Input
                    type="date"
                    className="h-9 w-full sm:w-40"
                    aria-label="Collection day"
                    value={filters.day}
                    onChange={(e) => e.target.value && visit({ day: e.target.value })}
                />
                <Button
                    variant="outline"
                    size="icon"
                    className="size-9"
                    aria-label="Next day"
                    onClick={() => visit({ day: shiftDay(filters.day, 1) })}
                >
                    <ChevronRight className="size-4" aria-hidden />
                </Button>
                {!filters.isToday && (
                    <Button variant="ghost" size="sm" className="h-9" onClick={() => visit({ day: filters.today })}>
                        Today
                    </Button>
                )}
            </div>
            {filters.shopLocked ? (
                <StatusPill tone="neutral" className="h-9 gap-1.5 px-3">
                    <Lock className="size-3.5" aria-hidden />
                    {shops[0]?.label ?? 'Your shop'}
                </StatusPill>
            ) : (
                shops.length > 1 && (
                    <FilterSelect
                        value={filters.shop}
                        onChange={(shop) => visit({ shop: shop ?? 'all' })}
                        all="Every shop"
                        options={shops}
                        label="Filter by shop"
                        width="sm:w-52"
                    />
                )
            )}
        </div>
    );
}

/** "Taking orders now": stopping asks first (customers can still look around but cannot order). */
export function TakingOrdersToggle({ taking }: { taking: boolean }) {
    const [asking, setAsking] = useState(false);
    const set = (value: boolean) =>
        new Promise<void>((resolve) =>
            router.put(route('app.online-orders.taking'), { taking: value }, { preserveScroll: true, onFinish: () => resolve() }),
        );

    return (
        <>
            <label className="bg-card flex h-9 cursor-pointer items-center gap-2.5 rounded-lg border px-3 text-sm font-medium">
                <Checkbox
                    checked={taking}
                    onCheckedChange={(checked) => (checked ? void set(true) : setAsking(true))}
                    aria-label="Taking orders now"
                />
                Taking orders now
            </label>
            <ConfirmDialog
                open={asking}
                onOpenChange={setAsking}
                title="Stop taking online orders?"
                description="Your online store stays open for customers to look around, but nobody can place an order until you turn this back on. Orders already placed are not affected."
                confirmLabel="Stop taking orders"
                destructive
                onConfirm={() => set(false)}
            />
        </>
    );
}

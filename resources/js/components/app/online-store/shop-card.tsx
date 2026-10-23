import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { timeZoneLabel } from '@/lib/country';
import { useForm } from '@inertiajs/react';
import { Copy, LoaderCircle } from 'lucide-react';
import { ToggleSwitch } from './toggle-switch';
import { DAY_NAMES, type CollectionShop, type ShopDay } from './types';

type DayForm = { day: ShopDay['day']; open: boolean; from: string; to: string };

type ShopForm = {
    enabled: boolean;
    days: DayForm[];
    prep_minutes: string;
    slot_minutes: string;
    orders_per_slot: string;
};

function initial(shop: CollectionShop): ShopForm {
    return {
        enabled: shop.saved ? shop.enabled : true,
        days: shop.days.map((d) => ({ day: d.day, open: d.open !== null, from: d.open ?? '09:00', to: d.close ?? '17:00' })),
        prep_minutes: String(shop.prepMinutes),
        slot_minutes: String(shop.slotMinutes),
        orders_per_slot: String(shop.ordersPerSlot),
    };
}

const minutes = (time: string) => {
    const [h, m] = time.split(':').map(Number);

    return h * 60 + m;
};

/** How many slots and orders a week the settings give (a guide; the store makes the real slots). */
function capacity(data: ShopForm): { days: number; slots: number; orders: number } {
    const slot = Number(data.slot_minutes) || 0;
    const per = Number(data.orders_per_slot) || 0;
    let slots = 0;
    let days = 0;
    for (const d of data.days) {
        if (d.open && slot > 0 && d.to > d.from) {
            days++;
            slots += Math.floor((minutes(d.to) - minutes(d.from)) / slot);
        }
    }

    return { days, slots, orders: slots * per };
}

interface ShopCardProps {
    shop: CollectionShop;
    slotOptions: number[];
    disabled: boolean;
}

/** One shop's click & collect settings: on or off, collection hours per weekday, ready time, slot length, orders per slot. */
export function ShopCard({ shop, slotOptions, disabled }: ShopCardProps) {
    const form = useForm<ShopForm>(initial(shop));
    const errors = form.errors as Record<string, string | undefined>;
    const off = disabled || form.processing;
    const cap = capacity(form.data);
    const setDay = (i: number, patch: Partial<DayForm>) =>
        form.setData(
            'days',
            form.data.days.map((d, j) => (j === i ? { ...d, ...patch } : d)),
        );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            enabled: data.enabled,
            opening_hours: Object.fromEntries(data.days.map((d) => [d.day, d.open ? { open: d.from, close: d.to } : null])),
            prep_minutes: data.prep_minutes,
            slot_minutes: data.slot_minutes,
            orders_per_slot: data.orders_per_slot,
        }));
        form.put(route('app.online-store.shops.update', shop.id), { preserveScroll: true, preserveState: true, onSuccess: () => form.setDefaults() });
    };

    const status = !shop.saved ? (
        <StatusPill tone="neutral">Not set up</StatusPill>
    ) : shop.enabled ? (
        <StatusPill tone="success">Collecting</StatusPill>
    ) : (
        <StatusPill tone="neutral">Off</StatusPill>
    );

    return (
        <form onSubmit={submit}>
            <SectionCard
                title={
                    <span className="flex flex-wrap items-center gap-2">
                        {shop.name}
                        {status}
                    </span>
                }
                description={[shop.code, shop.town].filter(Boolean).join(' · ')}
                actions={
                    <div className="flex items-center gap-2">
                        <Label htmlFor={`collect-${shop.id}`} className="text-sm font-normal">
                            Customers can collect here
                        </Label>
                        <ToggleSwitch
                            id={`collect-${shop.id}`}
                            checked={form.data.enabled}
                            disabled={off}
                            onCheckedChange={(on) => form.setData('enabled', on)}
                        />
                    </div>
                }
                footer={
                    <div className="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className={errors.store ? 'text-danger-foreground text-sm' : 'text-muted-foreground text-sm'}>
                            {errors.store ??
                                (form.data.enabled
                                    ? `${cap.slots} slots over ${cap.days} ${cap.days === 1 ? 'day' : 'days'} a week, up to ${cap.orders} orders.`
                                    : 'Not offered to customers.')}
                        </p>
                        <div className="flex gap-2">
                            {form.isDirty && (
                                <Button type="button" variant="outline" disabled={form.processing} onClick={() => form.reset()}>
                                    Discard
                                </Button>
                            )}
                            <Button type="submit" disabled={off || (shop.saved && !form.isDirty)}>
                                {form.processing && <LoaderCircle className="animate-spin" />}
                                {shop.saved ? 'Save shop' : 'Add as collection shop'}
                            </Button>
                        </div>
                    </div>
                }
            >
                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,18rem)]">
                    <div className="grid content-start gap-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-sm font-semibold">Collection hours</p>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={off}
                                onClick={() =>
                                    form.setData(
                                        'days',
                                        form.data.days.map((d) => ({ ...form.data.days[0], day: d.day })),
                                    )
                                }
                            >
                                <Copy />
                                Use Monday&apos;s hours every day
                            </Button>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            {timeZoneLabel()} time.
                            {!shop.saved && shop.hoursSource === 'calendar' && ' Copied from this shop’s opening hours.'}
                            {!shop.saved && shop.hoursSource === 'default' && ' Suggested hours: change them to suit the shop.'}
                        </p>
                        <div className="divide-border grid divide-y rounded-lg border">
                            {form.data.days.map((d, i) => {
                                const error =
                                    errors[`opening_hours.${d.day}.close`] ??
                                    errors[`opening_hours.${d.day}.open`] ??
                                    errors[`opening_hours.${d.day}`];

                                return (
                                    <div key={d.day} className="grid grid-cols-1 gap-2 px-3 py-2 sm:grid-cols-[8rem_1fr] sm:items-center">
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id={`${shop.id}-${d.day}`}
                                                checked={d.open}
                                                disabled={off}
                                                onCheckedChange={(on) => setDay(i, { open: on === true })}
                                            />
                                            <Label htmlFor={`${shop.id}-${d.day}`} className="font-medium">
                                                {DAY_NAMES[d.day]}
                                            </Label>
                                        </div>
                                        {d.open ? (
                                            <div className="grid gap-1">
                                                <div className="flex items-center gap-2">
                                                    <Input
                                                        type="time"
                                                        className="h-9 w-32"
                                                        value={d.from}
                                                        disabled={off}
                                                        aria-label={`${DAY_NAMES[d.day]} from`}
                                                        aria-invalid={!!error}
                                                        onChange={(e) => setDay(i, { from: e.target.value })}
                                                    />
                                                    <span className="text-muted-foreground text-sm">to</span>
                                                    <Input
                                                        type="time"
                                                        className="h-9 w-32"
                                                        value={d.to}
                                                        disabled={off}
                                                        aria-label={`${DAY_NAMES[d.day]} to`}
                                                        aria-invalid={!!error}
                                                        onChange={(e) => setDay(i, { to: e.target.value })}
                                                    />
                                                </div>
                                                {error && <p className="text-danger-foreground text-xs">{error}</p>}
                                            </div>
                                        ) : (
                                            <p className="text-muted-foreground text-sm">No collections</p>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                        {errors.opening_hours && <p className="text-danger-foreground text-xs">{errors.opening_hours}</p>}
                    </div>
                    <div className="grid content-start gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor={`${shop.id}-prep`}>Ready in</Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id={`${shop.id}-prep`}
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    max={1440}
                                    className="h-9 w-24"
                                    value={form.data.prep_minutes}
                                    disabled={off}
                                    aria-invalid={!!errors.prep_minutes}
                                    onChange={(e) => form.setData('prep_minutes', e.target.value)}
                                />
                                <span className="text-muted-foreground text-sm">minutes</span>
                            </div>
                            {errors.prep_minutes ? (
                                <p className="text-danger-foreground text-xs">{errors.prep_minutes}</p>
                            ) : (
                                <p className="text-muted-foreground text-xs">
                                    The earliest slot a customer can pick is at least this long after they order.
                                </p>
                            )}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${shop.id}-slot`}>Slot length</Label>
                            <Select value={form.data.slot_minutes} disabled={off} onValueChange={(v) => form.setData('slot_minutes', v)}>
                                <SelectTrigger id={`${shop.id}-slot`} className="h-9 w-40" aria-invalid={!!errors.slot_minutes}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(slotOptions.includes(Number(form.data.slot_minutes))
                                        ? slotOptions
                                        : [...slotOptions, Number(form.data.slot_minutes)].sort((a, b) => a - b)
                                    ).map((m) => (
                                        <SelectItem key={m} value={String(m)}>
                                            {m < 60
                                                ? `${m} minutes`
                                                : m % 60 === 0
                                                  ? `${m / 60} ${m === 60 ? 'hour' : 'hours'}`
                                                  : `${Math.floor(m / 60)} h ${m % 60} min`}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.slot_minutes && <p className="text-danger-foreground text-xs">{errors.slot_minutes}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${shop.id}-per`}>Orders per slot</Label>
                            <Input
                                id={`${shop.id}-per`}
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={100}
                                className="h-9 w-24"
                                value={form.data.orders_per_slot}
                                disabled={off}
                                aria-invalid={!!errors.orders_per_slot}
                                onChange={(e) => form.setData('orders_per_slot', e.target.value)}
                            />
                            {errors.orders_per_slot ? (
                                <p className="text-danger-foreground text-xs">{errors.orders_per_slot}</p>
                            ) : (
                                <p className="text-muted-foreground text-xs">A full slot is no longer offered.</p>
                            )}
                        </div>
                    </div>
                </div>
            </SectionCard>
        </form>
    );
}

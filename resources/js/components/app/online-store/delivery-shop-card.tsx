import { MoneyInput } from '@/components/app/products/fields';
import { FormField } from '@/components/shared/form-section';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { ToggleSwitch } from './toggle-switch';
import { type DeliveryShop, minutesLabel } from './types';
import { type DayForm, dayForms, WeekHoursEditor, weekPayload } from './week-hours-editor';

type DeliveryForm = {
    enabled: boolean;
    areas: string;
    fee: string;
    freeOver: string;
    minimum: string;
    days: DayForm[];
    slotMinutes: string;
    ordersPerSlot: string;
    leadMinutes: string;
};

const initial = (shop: DeliveryShop): DeliveryForm => ({
    enabled: shop.enabled,
    areas: shop.areas.join(', '),
    fee: shop.fee,
    freeOver: shop.freeOver ?? '',
    minimum: shop.minimum ?? '',
    days: dayForms(shop.days),
    slotMinutes: String(shop.slotMinutes),
    ordersPerSlot: String(shop.ordersPerSlot),
    leadMinutes: String(shop.leadMinutes),
});

/** OS-9: one collection shop's local delivery: on or off, postcode areas, fee, free-over, minimum, and its own slots. */
export function DeliveryShopCard({ shop, slotOptions, disabled }: { shop: DeliveryShop; slotOptions: number[]; disabled: boolean }) {
    const form = useForm<DeliveryForm>(initial(shop));
    const errors = form.errors as Record<string, string | undefined>;
    const off = disabled || form.processing;
    const id = (name: string) => `${shop.id}-${name}`;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            delivery_enabled: data.enabled,
            delivery_areas: data.areas,
            delivery_fee: data.fee,
            delivery_free_over: data.freeOver,
            delivery_minimum: data.minimum,
            delivery_hours: weekPayload(data.days),
            delivery_slot_minutes: data.slotMinutes,
            delivery_orders_per_slot: data.ordersPerSlot,
            delivery_lead_minutes: data.leadMinutes,
        }));
        form.put(route('app.online-store.delivery.shop', shop.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => form.setDefaults(),
        });
    };
    const options = slotOptions.includes(Number(form.data.slotMinutes))
        ? slotOptions
        : [...slotOptions, Number(form.data.slotMinutes)].sort((a, b) => a - b);

    return (
        <form onSubmit={submit}>
            <SectionCard
                title={
                    <span className="flex flex-wrap items-center gap-2">
                        {shop.name}
                        {shop.enabled ? <StatusPill tone="success">Delivering</StatusPill> : <StatusPill tone="neutral">No delivery</StatusPill>}
                        {!shop.collects && <StatusPill tone="warning">Collection off</StatusPill>}
                    </span>
                }
                description={[shop.code, shop.town, shop.postcode].filter(Boolean).join(' · ')}
                actions={
                    <div className="flex items-center gap-2">
                        <Label htmlFor={id('delivers')} className="text-sm font-normal">
                            Delivers to customers
                        </Label>
                        <ToggleSwitch
                            id={id('delivers')}
                            checked={form.data.enabled}
                            disabled={off}
                            onCheckedChange={(on) => form.setData('enabled', on)}
                        />
                    </div>
                }
                footer={
                    <div className="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className={errors.branch_id ? 'text-danger-foreground text-sm' : 'text-muted-foreground text-sm'}>
                            {errors.branch_id ??
                                (shop.collects
                                    ? 'Customers choose delivery at checkout when their postcode is in your area.'
                                    : 'This shop is switched off on the Collection shops tab, so customers do not see it.')}
                        </p>
                        <div className="flex gap-2">
                            {form.isDirty && (
                                <Button type="button" variant="outline" disabled={form.processing} onClick={() => form.reset()}>
                                    Discard
                                </Button>
                            )}
                            <Button type="submit" disabled={off || !form.isDirty}>
                                {form.processing && <LoaderCircle className="animate-spin" />}
                                Save delivery
                            </Button>
                        </div>
                    </div>
                }
            >
                <div className="grid gap-6 lg:grid-cols-2">
                    <div className="grid content-start gap-4">
                        <FormField
                            id={id('areas')}
                            label="Postcode areas you deliver to"
                            error={errors.delivery_areas}
                            help="Districts like B23, or sectors like B24 9, separated by commas. We match the customer's full postcode."
                        >
                            <Textarea
                                id={id('areas')}
                                rows={3}
                                value={form.data.areas}
                                disabled={off}
                                aria-invalid={!!errors.delivery_areas}
                                placeholder="B23, B24 9, B72"
                                onChange={(e) => form.setData('areas', e.target.value.toUpperCase())}
                            />
                        </FormField>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <FormField id={id('fee')} label="Delivery fee" error={errors.delivery_fee} help="0 for free delivery.">
                                <MoneyInput
                                    id={id('fee')}
                                    value={form.data.fee}
                                    disabled={off}
                                    invalid={!!errors.delivery_fee}
                                    onChange={(e) => form.setData('fee', e.target.value)}
                                />
                            </FormField>
                            <FormField id={id('free')} label="Free over" optional error={errors.delivery_free_over} help="Items total.">
                                <MoneyInput
                                    id={id('free')}
                                    value={form.data.freeOver}
                                    disabled={off}
                                    invalid={!!errors.delivery_free_over}
                                    onChange={(e) => form.setData('freeOver', e.target.value)}
                                />
                            </FormField>
                            <FormField id={id('min')} label="Minimum order" optional error={errors.delivery_minimum} help="Items total.">
                                <MoneyInput
                                    id={id('min')}
                                    value={form.data.minimum}
                                    disabled={off}
                                    invalid={!!errors.delivery_minimum}
                                    onChange={(e) => form.setData('minimum', e.target.value)}
                                />
                            </FormField>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <FormField id={id('slot')} label="Slot length" error={errors.delivery_slot_minutes}>
                                <Select value={form.data.slotMinutes} disabled={off} onValueChange={(v) => form.setData('slotMinutes', v)}>
                                    <SelectTrigger id={id('slot')} className="h-9" aria-invalid={!!errors.delivery_slot_minutes}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.map((m) => (
                                            <SelectItem key={m} value={String(m)}>
                                                {minutesLabel(m)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField id={id('per')} label="Orders per slot" error={errors.delivery_orders_per_slot}>
                                <Input
                                    id={id('per')}
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={100}
                                    className="h-9"
                                    value={form.data.ordersPerSlot}
                                    disabled={off}
                                    aria-invalid={!!errors.delivery_orders_per_slot}
                                    onChange={(e) => form.setData('ordersPerSlot', e.target.value)}
                                />
                            </FormField>
                            <FormField id={id('lead')} label="Lead time" error={errors.delivery_lead_minutes} help="Minutes before the first slot.">
                                <Input
                                    id={id('lead')}
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    max={2880}
                                    className="h-9"
                                    value={form.data.leadMinutes}
                                    disabled={off}
                                    aria-invalid={!!errors.delivery_lead_minutes}
                                    onChange={(e) => form.setData('leadMinutes', e.target.value)}
                                />
                            </FormField>
                        </div>
                    </div>
                    <WeekHoursEditor
                        idPrefix={id('delivery')}
                        title={shop.hoursSaved ? 'Delivery hours' : 'Delivery hours (copied from collection hours)'}
                        days={form.data.days}
                        onChange={(days) => form.setData('days', days)}
                        errors={errors}
                        errorKey="delivery_hours"
                        disabled={off}
                        closedText="No deliveries"
                    />
                </div>
            </SectionCard>
        </form>
    );
}

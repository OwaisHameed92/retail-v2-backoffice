import { MoneyInput, NumberField, OptionSelect } from '@/components/app/products/fields';
import { CheckField } from '@/components/app/setup/fields';
import { type PromotionFormProps, type PromotionValues } from '@/components/app/pricing/types';
import { FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { DaysPicker, PriceTiersEditor, TYPE_HELP, TypePicker } from './offer-pickers';

export interface OfferSectionProps {
    data: PromotionValues;
    set: <K extends keyof PromotionValues>(key: K, value: PromotionValues[K]) => void;
    errors: Record<string, string>;
    options: PromotionFormProps['options'];
}

export const ITEM_TYPES = ['mixMatch', 'mealDeal'];

/** Type and what it gives (only the values the chosen type uses). */
export function DealSection({ data, set, errors, options }: OfferSectionProps) {
    const uses = (...types: string[]) => types.includes(data.type);

    return (
        <FormSection title="The deal" description={TYPE_HELP[data.type] ?? 'What the customer gets.'}>
            <FormField id="name" label="Name" help="Shown on the till and the receipt." error={errors.name}>
                <Input id="name" maxLength={255} value={data.name} aria-invalid={!!errors.name} onChange={(e) => set('name', e.target.value)} />
            </FormField>
            <FormField id="type" label="Type of offer" error={errors.type}>
                <TypePicker
                    value={data.type}
                    options={options.types}
                    invalid={!!errors.type}
                    onChange={(value) => {
                        set('type', value);
                        if (value === 'quantityPrice' && data.scope !== 'product') {
                            set('scope', 'product');
                            set('target_id', '');
                        }
                    }}
                />
            </FormField>
            {uses('quantityPrice') && <PriceTiersEditor tiers={data.price_tiers} onChange={(tiers) => set('price_tiers', tiers)} error={errors.price_tiers} disabled={false} />}
            <FormGrid columns={3}>
                {uses('multiBuy', 'bogof', 'buyGet', 'mixMatch') && (
                    <FormField id="buy_quantity" label="Buy" error={errors.buy_quantity}>
                        <NumberField id="buy_quantity" inputMode="numeric" suffix="items" value={data.buy_quantity} invalid={!!errors.buy_quantity} onChange={(e) => set('buy_quantity', e.target.value)} />
                    </FormField>
                )}
                {uses('bogof', 'buyGet') && (
                    <FormField id="get_quantity" label="Get" error={errors.get_quantity}>
                        <NumberField id="get_quantity" inputMode="numeric" suffix="items" value={data.get_quantity} invalid={!!errors.get_quantity} onChange={(e) => set('get_quantity', e.target.value)} />
                    </FormField>
                )}
                {uses('percentOff', 'buyGet') && (
                    <FormField id="percent" label={data.type === 'buyGet' ? 'Off the free items' : 'Percent off'} error={errors.percent}>
                        <NumberField id="percent" suffix="%" value={data.percent} invalid={!!errors.percent} onChange={(e) => set('percent', e.target.value)} />
                    </FormField>
                )}
                {uses('fixedOff') && (
                    <FormField id="amount_off" label="Amount off" error={errors.amount_off}>
                        <MoneyInput id="amount_off" value={data.amount_off} invalid={!!errors.amount_off} onChange={(e) => set('amount_off', e.target.value)} />
                    </FormField>
                )}
                {uses('fixedPrice', 'multiBuy', 'mixMatch', 'mealDeal') && (
                    <FormField id="deal_price" label={data.type === 'fixedPrice' ? 'Offer price' : 'Price for the deal'} error={errors.deal_price}>
                        <MoneyInput id="deal_price" value={data.deal_price} invalid={!!errors.deal_price} onChange={(e) => set('deal_price', e.target.value)} />
                    </FormField>
                )}
                {uses('percentOff', 'fixedOff', 'fixedPrice') && (
                    <FormField id="min_quantity" label="Minimum quantity" help="2 or more makes it a group offer (once per group)." error={errors.min_quantity}>
                        <NumberField id="min_quantity" inputMode="numeric" value={data.min_quantity} invalid={!!errors.min_quantity} onChange={(e) => set('min_quantity', e.target.value)} />
                    </FormField>
                )}
            </FormGrid>
        </FormSection>
    );
}

const SCOPES = [
    { value: 'product', label: 'A product' },
    { value: 'category', label: 'A category' },
    { value: 'department', label: 'A department' },
    { value: 'basket', label: 'The whole basket' },
];

/** What a single-target offer is on. */
export function TargetSection({ data, set, errors, options }: OfferSectionProps) {
    const known = SCOPES.some((s) => s.value === data.scope);
    const targets = data.scope === 'product' ? options.products : data.scope === 'category' ? options.categories : options.departments;
    const scopes = known ? SCOPES : [...SCOPES, { value: data.scope, label: 'As set on the till' }];

    return (
        <FormSection title="What it is on">
            <FormGrid>
                <FormField id="scope" label="Applies to" error={errors.scope}>
                    <OptionSelect
                        id="scope"
                        value={data.scope}
                        options={
                            data.type === 'quantityPrice'
                                ? scopes.filter((s) => s.value === 'product')
                                : data.type === 'percentOff' || data.type === 'fixedOff'
                                  ? scopes
                                  : scopes.filter((s) => s.value !== 'basket')
                        }
                        onChange={(value) => {
                            set('scope', value);
                            set('target_id', '');
                        }}
                    />
                </FormField>
                {['product', 'category', 'department'].includes(data.scope) && (
                    <FormField id="target_id" label={data.scope[0].toUpperCase() + data.scope.slice(1)} error={errors.target_id}>
                        <OptionSelect id="target_id" value={data.target_id} options={targets} invalid={!!errors.target_id} onChange={(value) => set('target_id', value)} />
                    </FormField>
                )}
            </FormGrid>
        </FormSection>
    );
}

/** Shop, dates, times, limits and coupon. */
export function WhenSection({ data, set, errors, options, restricted }: OfferSectionProps & { restricted: boolean }) {
    const pastMidnight = data.time_from !== '' && data.time_to !== '' && data.time_to < data.time_from;

    return (
        <FormSection title="Where and when" description="Dates are whole days; times are UK shop time on each chosen day.">
            <FormGrid>
                <FormField id="branch_id" label="Shops" help={restricted ? 'You manage one shop.' : 'One shop, or every shop.'} error={errors.branch_id}>
                    <OptionSelect
                        id="branch_id"
                        value={data.branch_id}
                        none={restricted ? undefined : 'All shops'}
                        options={options.shops}
                        disabled={restricted}
                        onChange={(value) => set('branch_id', value)}
                    />
                </FormField>
                <FormField id="priority" label="Priority" optional help="Higher wins when offers compete." error={errors.priority}>
                    <NumberField id="priority" inputMode="numeric" value={data.priority} onChange={(e) => set('priority', e.target.value)} />
                </FormField>
                <FormField id="effective_from" label="Starts" error={errors.effective_from}>
                    <Input id="effective_from" type="date" value={data.effective_from} aria-invalid={!!errors.effective_from} onChange={(e) => set('effective_from', e.target.value)} />
                </FormField>
                <FormField id="effective_to" label="Ends" optional help="Last day it runs." error={errors.effective_to}>
                    <Input id="effective_to" type="date" value={data.effective_to} aria-invalid={!!errors.effective_to} onChange={(e) => set('effective_to', e.target.value)} />
                </FormField>
                <FormField id="days" label="Days" help="Leave all off, or all on, for every day." error={errors.days} className="sm:col-span-2">
                    <DaysPicker value={data.days} onChange={(days) => set('days', days)} />
                </FormField>
                <FormField id="time_from" label="From time" optional error={errors.time_from}>
                    <Input id="time_from" type="time" value={data.time_from} onChange={(e) => set('time_from', e.target.value)} />
                </FormField>
                <FormField
                    id="time_to"
                    label="To time"
                    optional
                    help={pastMidnight ? 'Runs past midnight. The days are checked on the calendar date, so tick the next day too (Fri 22:00–02:00 needs Fri and Sat).' : 'Before the start time = runs past midnight.'}
                    error={errors.time_to}
                >
                    <Input id="time_to" type="time" value={data.time_to} aria-invalid={!!errors.time_to} onChange={(e) => set('time_to', e.target.value)} />
                </FormField>
                <FormField id="max_redemptions_per_sale" label="Most per sale" optional error={errors.max_redemptions_per_sale}>
                    <NumberField id="max_redemptions_per_sale" inputMode="numeric" value={data.max_redemptions_per_sale} onChange={(e) => set('max_redemptions_per_sale', e.target.value)} />
                </FormField>
                <FormField id="max_redemptions_total" label="Most in total" optional error={errors.max_redemptions_total}>
                    <NumberField id="max_redemptions_total" inputMode="numeric" value={data.max_redemptions_total} onChange={(e) => set('max_redemptions_total', e.target.value)} />
                </FormField>
            </FormGrid>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <CheckField id="allow_stack" label="Can combine with other offers" checked={data.allow_stack} onChange={(v) => set('allow_stack', v)} />
                <CheckField id="is_exclusive" label="Exclusive" help="No other offer on the same items." checked={data.is_exclusive} onChange={(v) => set('is_exclusive', v)} />
                <CheckField id="is_hfss_safe" label="Allowed on HFSS food" help="Only if it is not a volume offer on less healthy food." checked={data.is_hfss_safe} onChange={(v) => set('is_hfss_safe', v)} />
                <CheckField id="requires_coupon" label="Needs a coupon code" checked={data.requires_coupon} onChange={(v) => set('requires_coupon', v)} />
            </div>
            {data.requires_coupon && (
                <FormField id="coupon_code" label="Coupon code" error={errors.coupon_code}>
                    <Input id="coupon_code" maxLength={64} value={data.coupon_code} aria-invalid={!!errors.coupon_code} onChange={(e) => set('coupon_code', e.target.value.toUpperCase())} />
                </FormField>
            )}
        </FormSection>
    );
}

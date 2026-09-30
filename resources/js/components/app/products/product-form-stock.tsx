import { FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Boxes, Plus, Trash2 } from 'lucide-react';
import { CheckRow, MoneyInput, NumberField, OptionSelect } from './fields';
import { type SectionProps, type UnitValue } from './types';

const UNIT_TYPES = [
    { value: 'pcs', label: 'Sold by the item' },
    { value: 'kg', label: 'Sold by weight' },
    { value: 'open', label: 'Open quantity' },
];

const NEGATIVE_STOCK = [
    { value: 'allow', label: 'Allow selling' },
    { value: 'warn', label: 'Warn the cashier' },
    { value: 'block', label: 'Block the sale' },
];

/** How it is sold and counted: unit, stock tracking, levels, expiry. */
export function StockSection({ data, setData, errors, options }: SectionProps) {
    const unitCodes = options.units.length > 0 ? options.units.map((u) => ({ value: u.code, label: `${u.label} (${u.code})` })) : null;

    return (
        <FormSection title="Selling and stock" description="How the till sells it and whether it counts stock.">
            <FormGrid columns={3}>
                <FormField id="unit_type" label="Sold as" error={errors.unit_type}>
                    <OptionSelect id="unit_type" value={data.unit_type} options={UNIT_TYPES} onChange={(value) => setData('unit_type', value)} />
                </FormField>
                <FormField id="unit_code" label="Unit" error={errors.unit_code}>
                    {unitCodes ? (
                        <OptionSelect id="unit_code" value={data.unit_code} options={unitCodes} invalid={!!errors.unit_code} onChange={(value) => setData('unit_code', value)} />
                    ) : (
                        <Input id="unit_code" maxLength={20} value={data.unit_code} aria-invalid={!!errors.unit_code} onChange={(e) => setData('unit_code', e.target.value.toUpperCase())} />
                    )}
                </FormField>
                <FormField id="bin_location" label="Shelf or bin" optional error={errors.bin_location}>
                    <Input id="bin_location" maxLength={40} value={data.bin_location} onChange={(e) => setData('bin_location', e.target.value)} />
                </FormField>
            </FormGrid>
            <div className="grid gap-3 sm:grid-cols-2">
                <CheckRow id="is_weighed" checked={data.is_weighed} onChange={(v) => setData('is_weighed', v)} label="Weighed at the till" help="The scale sets the quantity." />
                <CheckRow id="is_open_price" checked={data.is_open_price} onChange={(v) => setData('is_open_price', v)} label="Price entered at the till" help="The cashier keys the price in." />
                <CheckRow id="tracks_expiry_dates" checked={data.tracks_expiry_dates} onChange={(v) => setData('tracks_expiry_dates', v)} label="Track expiry dates" help="Goods-in asks for best-before dates." />
                <CheckRow id="track_stock" checked={data.track_stock} onChange={(v) => setData('track_stock', v)} label="Track stock" help="Sales and deliveries change the stock level." />
            </div>
            {data.track_stock && (
                <FormGrid columns={3}>
                    <FormField id="min_stock_qty" label="Low stock at" optional error={errors.min_stock_qty}>
                        <NumberField id="min_stock_qty" value={data.min_stock_qty} invalid={!!errors.min_stock_qty} onChange={(e) => setData('min_stock_qty', e.target.value)} />
                    </FormField>
                    <FormField id="reorder_qty" label="Reorder quantity" optional error={errors.reorder_qty}>
                        <NumberField id="reorder_qty" value={data.reorder_qty} invalid={!!errors.reorder_qty} onChange={(e) => setData('reorder_qty', e.target.value)} />
                    </FormField>
                    <FormField id="max_stock_qty" label="Maximum stock" optional error={errors.max_stock_qty}>
                        <NumberField id="max_stock_qty" value={data.max_stock_qty} invalid={!!errors.max_stock_qty} onChange={(e) => setData('max_stock_qty', e.target.value)} />
                    </FormField>
                    <FormField id="negative_stock_mode" label="When out of stock" optional help="Empty: the category's or the till's setting." error={errors.negative_stock_mode}>
                        <OptionSelect id="negative_stock_mode" value={data.negative_stock_mode} options={NEGATIVE_STOCK} none="Use the default" onChange={(v) => setData('negative_stock_mode', v)} />
                    </FormField>
                </FormGrid>
            )}
        </FormSection>
    );
}

/** Pack sizes (a case of 12…) with their own price and cost. Existing rows keep their id. */
export function UnitEditor({ data, setData, errors, options }: SectionProps) {
    const rows = data.units;
    const set = (next: UnitValue[]) => setData('units', next);
    const update = (index: number, patch: Partial<UnitValue>) => set(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    const units = options.units.map((u) => ({ value: u.value, label: `${u.label} (${u.code})` }));

    if (options.units.length === 0) {
        return (
            <FormSection title="Pack sizes" description="Sell or buy it in packs, each with its own price.">
                <p className="text-muted-foreground flex items-center gap-2 text-sm">
                    <Boxes className="size-4" aria-hidden />
                    Your units (each, case, kg…) arrive from your till at its first sync. Pack sizes can be added then.
                </p>
            </FormSection>
        );
    }

    return (
        <FormSection title="Pack sizes" description="Sell or buy it in packs, each with its own price. “How many” is in the product's own unit.">
            {rows.map((row, index) => {
                const e = (key: string) => errors[`units.${index}.${key}`];

                return (
                    <div key={row.id ?? `new-${index}`} className="grid gap-3 rounded-lg border p-3.5">
                        <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_7rem_8rem_8rem_auto] sm:items-end">
                            <FormField id={`units-${index}-unit`} label="Unit" error={e('unit_id')}>
                                <OptionSelect id={`units-${index}-unit`} value={row.unit_id} options={units} invalid={!!e('unit_id')} onChange={(v) => update(index, { unit_id: v })} />
                            </FormField>
                            <FormField id={`units-${index}-factor`} label="How many" error={e('conversion_factor')}>
                                <NumberField id={`units-${index}-factor`} value={row.conversion_factor} invalid={!!e('conversion_factor')} onChange={(ev) => update(index, { conversion_factor: ev.target.value })} />
                            </FormField>
                            <FormField id={`units-${index}-price`} label="Price" error={e('sell_price_inc_vat')}>
                                <MoneyInput id={`units-${index}-price`} value={row.sell_price_inc_vat} invalid={!!e('sell_price_inc_vat')} onChange={(ev) => update(index, { sell_price_inc_vat: ev.target.value })} />
                            </FormField>
                            <FormField id={`units-${index}-cost`} label="Cost" error={e('cost')}>
                                <MoneyInput id={`units-${index}-cost`} places={4} value={row.cost} invalid={!!e('cost')} onChange={(ev) => update(index, { cost: ev.target.value })} />
                            </FormField>
                            <Button type="button" variant="ghost" size="icon" aria-label={`Remove pack size ${index + 1}`} onClick={() => set(rows.filter((_, i) => i !== index))}>
                                <Trash2 />
                            </Button>
                        </div>
                        <div className="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                            {(
                                [
                                    ['is_default_sell_unit', 'Default when selling'],
                                    ['is_purchase_unit', 'Bought in this pack'],
                                    ['is_default_purchase_unit', 'Default when ordering'],
                                ] as const
                            ).map(([key, label]) => (
                                <label key={key} className="flex cursor-pointer items-center gap-2">
                                    <Checkbox checked={row[key]} onCheckedChange={(state) => update(index, { [key]: state === true })} />
                                    {label}
                                </label>
                            ))}
                        </div>
                    </div>
                );
            })}
            {rows.length < 10 && (
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            set([
                                ...rows,
                                { id: null, unit_id: '', conversion_factor: '1', sell_price_inc_vat: data.sell_price, cost: data.cost_price, is_default_sell_unit: false, is_purchase_unit: true, is_default_purchase_unit: false },
                            ])
                        }
                    >
                        <Plus />
                        Add pack size
                    </Button>
                </div>
            )}
        </FormSection>
    );
}

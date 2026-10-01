import { NumberField, OptionSelect } from '@/components/app/products/fields';
import { type PromotionItemValues } from '@/components/app/pricing/types';
import { FormSection } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Plus, Trash2 } from 'lucide-react';
import { type OfferSectionProps } from './offer-sections';

const KINDS = [
    { value: 'product', label: 'Product' },
    { value: 'category', label: 'Category' },
    { value: 'department', label: 'Department' },
    { value: 'style', label: 'Style' },
];

/**
 * The items of a mix-and-match or meal deal (PromotionItem rows): a product, category, department or style (every
 * size or colour of a product), which group (meal deal only: 1, 2, 3…), how many, or left out of the deal.
 */
export function OfferItems({ data, set, errors, options, disabled }: OfferSectionProps & { disabled: boolean }) {
    const mealDeal = data.type === 'mealDeal';
    const update = (index: number, patch: Partial<PromotionItemValues>) => set('items', data.items.map((item, i) => (i === index ? { ...item, ...patch } : item)));
    const add = () =>
        set('items', [...data.items, { id: null, scope: 'product', target_id: '', group_no: mealDeal ? String(Math.min(9, data.items.length + 1)) : '0', quantity: '1', is_excluded: false }]);
    const targets = (scope: string) =>
        scope === 'product' ? options.products : scope === 'category' ? options.categories : scope === 'style' ? options.styles : options.departments;

    return (
        <FormSection
            title={mealDeal ? 'Groups' : 'Items in the offer'}
            description={
                mealDeal
                    ? 'Give each part of the deal its own group number, 1, 2, 3…: e.g. 1 = main, 2 = snack, 3 = drink. The customer takes one from each group.'
                    : 'Any mix of these counts towards the deal. Tick "Leave out" to keep one item out, e.g. a whole category except one product.'
            }
        >
            {errors.items && <p className="text-destructive text-sm">{errors.items}</p>}
            <div className="grid gap-3">
                {data.items.map((item, i) => (
                    <div
                        key={item.id ?? `new-${i}`}
                        className={`grid grid-cols-1 items-end gap-2 rounded-lg border p-3 ${mealDeal ? 'sm:grid-cols-[8rem_1fr_5rem_5rem_auto_auto]' : 'sm:grid-cols-[8rem_1fr_5rem_auto_auto]'}`}
                    >
                        <OptionSelect id={`items-${i}-scope`} value={item.scope} options={KINDS} onChange={(v) => update(i, { scope: v as PromotionItemValues['scope'], target_id: '' })} />
                        <div className="grid gap-1">
                            <OptionSelect id={`items-${i}-target`} value={item.target_id} options={targets(item.scope)} invalid={!!errors[`items.${i}.target_id`]} onChange={(v) => update(i, { target_id: v })} />
                            {errors[`items.${i}.target_id`] && <p className="text-destructive text-xs">{errors[`items.${i}.target_id`]}</p>}
                        </div>
                        {mealDeal && (
                            <NumberField id={`items-${i}-group`} aria-label="Group" inputMode="numeric" value={item.group_no} suffix="grp" onChange={(e) => update(i, { group_no: e.target.value })} />
                        )}
                        <NumberField id={`items-${i}-qty`} aria-label="Quantity" inputMode="numeric" value={item.quantity} suffix="×" onChange={(e) => update(i, { quantity: e.target.value })} />
                        <label className="flex h-9 items-center gap-2 text-sm">
                            <Checkbox checked={item.is_excluded} onCheckedChange={(v) => update(i, { is_excluded: v === true })} />
                            Leave out
                        </label>
                        <Button type="button" variant="ghost" size="icon" disabled={disabled} aria-label="Remove item" onClick={() => set('items', data.items.filter((_, x) => x !== i))}>
                            <Trash2 />
                        </Button>
                    </div>
                ))}
            </div>
            {!disabled && (
                <Button type="button" variant="outline" className="justify-self-start" onClick={add}>
                    <Plus />
                    Add item
                </Button>
            )}
        </FormSection>
    );
}

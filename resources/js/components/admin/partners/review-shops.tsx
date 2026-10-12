import { type ReviewShop } from '@/components/admin/partners/types';
import { BranchFields, type BranchFieldsData } from '@/components/admin/tenants/branch-fields';
import { TillCountPicker } from '@/components/admin/tenants/till-count-picker';
import { type Nation, type Option } from '@/components/admin/tenants/types';
import { Button } from '@/components/ui/button';
import { Plus, Store, Trash2 } from 'lucide-react';

interface ReviewShopsProps {
    shops: ReviewShop[];
    onChange: (shops: ReviewShop[]) => void;
    errors: Partial<Record<string, string>>;
    nations: Option<Nation>[];
    maxTills: number;
    maxShops: number;
}

export const emptyShop = (nation: Nation): ReviewShop => ({
    code: '',
    name: '',
    nation,
    address: '',
    phone: '',
    vat_number: '',
    area_m2: '',
    is_drs_return_point: false,
    licensed_hours_json: '',
    town: '',
    postcode: '',
    receipt_footer: '',
    tills: 1,
    tills_allowed: 1,
});

/**
 * The shops of a partner request in the review form: the new-business wizard's branch fields for each (error keys
 * `shops.N.field`), its number of tills, and add / remove (at least one shop).
 */
export function ReviewShops({ shops, onChange, errors, nations, maxTills, maxShops }: ReviewShopsProps) {
    const update = (index: number, patch: Partial<ReviewShop>) => onChange(shops.map((shop, i) => (i === index ? { ...shop, ...patch } : shop)));

    return (
        <div className="grid gap-4 sm:col-span-2">
            {shops.map((shop, index) => {
                const prefix = `shops.${index}.`;
                const setField = <K extends keyof BranchFieldsData>(key: K, value: BranchFieldsData[K]) =>
                    update(index, { [key]: value } as Partial<ReviewShop>);

                return (
                    <section key={index} aria-labelledby={`shop-${index}-title`} className="grid gap-4 rounded-xl border p-4">
                        <div className="flex items-center justify-between gap-3">
                            <h3 id={`shop-${index}-title`} className="flex items-center gap-2 text-sm font-semibold">
                                <Store className="text-muted-foreground size-4" aria-hidden />
                                {shops.length > 1 ? `Shop ${index + 1}` : 'Shop'}
                                {shop.name && <span className="text-muted-foreground font-normal">· {shop.name}</span>}
                            </h3>
                            {shops.length > 1 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => onChange(shops.filter((_, i) => i !== index))}
                                    aria-label={`Remove ${shop.name || `shop ${index + 1}`}`}
                                >
                                    <Trash2 />
                                    Remove
                                </Button>
                            )}
                        </div>
                        <div className="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                            <BranchFields prefix={prefix} data={shop} setField={setField} errors={errors} nations={nations} />
                            <div className="sm:col-span-2">
                                <TillCountPicker
                                    id={`${prefix}tills`}
                                    value={shop.tills}
                                    max={maxTills}
                                    onChange={(tills) => update(index, { tills, tills_allowed: Math.max(tills, shop.tills_allowed) })}
                                    error={errors[`${prefix}tills`]}
                                />
                            </div>
                        </div>
                    </section>
                );
            })}
            {errors.shops && <p className="text-destructive text-sm">{errors.shops}</p>}
            {shops.length < maxShops && (
                <div>
                    <Button type="button" variant="outline" onClick={() => onChange([...shops, emptyShop(shops[0]?.nation ?? 'england')])}>
                        <Plus />
                        Add shop
                    </Button>
                </div>
            )}
        </div>
    );
}

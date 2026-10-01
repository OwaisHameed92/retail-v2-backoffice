import { CheckRow, MoneyInput, OptionSelect } from '@/components/app/products/fields';
import { FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { type ReactNode } from 'react';
import { type MasterOptions, type MasterValues } from './types';

interface Props {
    data: MasterValues;
    setData: <K extends keyof MasterValues>(key: K, value: MasterValues[K]) => void;
    errors: Partial<Record<keyof MasterValues, string>>;
    options: MasterOptions;
    /** False when the barcode is fixed (approving a till's barcode). */
    withBarcode?: boolean;
    /** Compact single-column layout for dialogs. */
    compact?: boolean;
}

function Group({ compact, title, description, children }: { compact: boolean; title: string; description: string; children: ReactNode }) {
    return compact ? (
        <div className="grid gap-4">{children}</div>
    ) : (
        <FormSection title={title} description={description}>
            {children}
        </FormSection>
    );
}

/** The fields of a master catalogue product: what a shop would otherwise key in. */
export function MasterFields({ data, setData, errors, options, withBarcode = true, compact = false }: Props) {
    return (
        <>
            <Group compact={compact} title="Product" description="How shops will find and name it.">
                <FormGrid>
                    {withBarcode && (
                        <FormField
                            id="barcode"
                            label="Barcode"
                            help="EAN-13, EAN-8, UPC-A or GTIN-14. The check digit must match."
                            error={errors.barcode}
                        >
                            <Input
                                id="barcode"
                                inputMode="numeric"
                                maxLength={20}
                                className="font-mono tabular-nums"
                                value={data.barcode}
                                aria-invalid={!!errors.barcode}
                                onChange={(e) => setData('barcode', e.target.value.trim())}
                            />
                        </FormField>
                    )}
                    <FormField id="brand" label="Brand" optional error={errors.brand}>
                        <Input id="brand" maxLength={120} value={data.brand} onChange={(e) => setData('brand', e.target.value)} />
                    </FormField>
                </FormGrid>
                <FormField id="name" label="Name" help="Brand, product and size, as a shop would show it." error={errors.name}>
                    <Input
                        id="name"
                        maxLength={255}
                        value={data.name}
                        aria-invalid={!!errors.name}
                        onChange={(e) => setData('name', e.target.value)}
                    />
                </FormField>
                <FormGrid columns={3}>
                    <FormField id="size_value" label="Size" optional error={errors.size_value}>
                        <Input id="size_value" inputMode="decimal" value={data.size_value} onChange={(e) => setData('size_value', e.target.value)} />
                    </FormField>
                    <FormField id="size_unit" label="Unit" optional error={errors.size_unit}>
                        <OptionSelect
                            id="size_unit"
                            value={data.size_unit}
                            options={options.units}
                            none="None"
                            onChange={(v) => setData('size_unit', v)}
                        />
                    </FormField>
                    <FormField id="pack_qty" label="Multipack of" optional help="4 for 4 x 440ml." error={errors.pack_qty}>
                        <Input id="pack_qty" inputMode="numeric" value={data.pack_qty} onChange={(e) => setData('pack_qty', e.target.value)} />
                    </FormField>
                </FormGrid>
            </Group>

            <Group compact={compact} title="Suggestions for shops" description="Shops can change all of these when they add the product.">
                <FormGrid>
                    <FormField id="department" label="Department" optional error={errors.department}>
                        <Input
                            id="department"
                            list="master-departments"
                            maxLength={120}
                            value={data.department}
                            onChange={(e) => setData('department', e.target.value)}
                        />
                        <datalist id="master-departments">
                            {options.departments.map((d) => (
                                <option key={d} value={d} />
                            ))}
                        </datalist>
                    </FormField>
                    <FormField id="category" label="Category" optional error={errors.category}>
                        <Input
                            id="category"
                            list="master-categories"
                            maxLength={120}
                            value={data.category}
                            onChange={(e) => setData('category', e.target.value)}
                        />
                        <datalist id="master-categories">
                            {options.categories.map((c) => (
                                <option key={c} value={c} />
                            ))}
                        </datalist>
                    </FormField>
                    <FormField id="vat_rate" label="VAT" optional error={errors.vat_rate}>
                        <OptionSelect
                            id="vat_rate"
                            value={data.vat_rate}
                            options={options.vatRates}
                            none="Not known"
                            onChange={(v) => setData('vat_rate', v)}
                        />
                    </FormField>
                    <FormField id="rrp" label="RRP" optional help="Recommended price including VAT." error={errors.rrp}>
                        <MoneyInput id="rrp" value={data.rrp} invalid={!!errors.rrp} onChange={(e) => setData('rrp', e.target.value)} />
                    </FormField>
                    <FormField id="age_rule" label="Age check" error={errors.age_rule}>
                        <OptionSelect id="age_rule" value={data.age_rule} options={options.ageRules} onChange={(v) => setData('age_rule', v)} />
                    </FormField>
                    <FormField id="image_url" label="Image address" optional help="https:// only." error={errors.image_url}>
                        <Input
                            id="image_url"
                            type="url"
                            maxLength={500}
                            value={data.image_url}
                            onChange={(e) => setData('image_url', e.target.value)}
                        />
                    </FormField>
                </FormGrid>
                <CheckRow
                    id="in_starter_packs"
                    checked={data.in_starter_packs}
                    onChange={(checked) => setData('in_starter_packs', checked)}
                    label="Offer in starter packs"
                    help="New businesses get it when they pick a starter pack that includes its department."
                />
            </Group>
        </>
    );
}

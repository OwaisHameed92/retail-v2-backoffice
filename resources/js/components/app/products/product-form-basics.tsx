import { FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Link } from '@inertiajs/react';
import { MoneyInput, OptionSelect, marginPercent } from './fields';
import { type SectionProps } from './types';
import { taxName, taxText } from '@/lib/country';

/** Name, till and receipt names, brand, code, description. */
export function BasicsSection({ data, setData, errors }: SectionProps) {
    return (
        <FormSection title="Product" description="What your staff and customers see.">
            <FormField id="name" label="Name" error={errors.name}>
                <Input id="name" required maxLength={255} value={data.name} aria-invalid={!!errors.name} onChange={(e) => setData('name', e.target.value)} />
            </FormField>
            <FormGrid>
                <FormField id="short_name" label="Till button name" optional help="Up to 40 characters. The name is used when empty." error={errors.short_name}>
                    <Input id="short_name" maxLength={40} value={data.short_name} aria-invalid={!!errors.short_name} onChange={(e) => setData('short_name', e.target.value)} />
                </FormField>
                <FormField id="receipt_name" label="Receipt name" optional help="Printed on receipts instead of the name." error={errors.receipt_name}>
                    <Input id="receipt_name" maxLength={60} value={data.receipt_name} aria-invalid={!!errors.receipt_name} onChange={(e) => setData('receipt_name', e.target.value)} />
                </FormField>
                <FormField id="brand" label="Brand" optional error={errors.brand}>
                    <Input id="brand" maxLength={120} value={data.brand} aria-invalid={!!errors.brand} onChange={(e) => setData('brand', e.target.value)} />
                </FormField>
                <FormField id="sku" label="Product code (SKU)" optional help="Your own code. Must be unique." error={errors.sku}>
                    <Input id="sku" maxLength={64} value={data.sku} aria-invalid={!!errors.sku} onChange={(e) => setData('sku', e.target.value)} />
                </FormField>
            </FormGrid>
            <FormField id="description" label="Description" optional error={errors.description}>
                <Textarea id="description" rows={3} maxLength={2000} value={data.description} onChange={(e) => setData('description', e.target.value)} />
            </FormField>
        </FormSection>
    );
}

/** Department, category (of that department) and optional sub-category (of that category). */
export function GroupSection({ data, setData, errors, options }: SectionProps) {
    const categories = options.categories.filter((c) => c.parentId === null && c.departmentId === data.department_id);
    const subs = options.categories.filter((c) => c.parentId !== null && c.parentId === data.category_id);

    return (
        <FormSection
            title="Department and category"
            description={
                <>
                    Where it files on the till and in reports.{' '}
                    <Link href={route('app.products.groups')} className="text-primary underline-offset-4 hover:underline">
                        Manage departments
                    </Link>
                </>
            }
        >
            <FormGrid columns={3}>
                <FormField id="department_id" label="Department" error={errors.department_id}>
                    <OptionSelect
                        id="department_id"
                        value={data.department_id}
                        options={options.departments}
                        invalid={!!errors.department_id}
                        onChange={(value) => {
                            setData('department_id', value);
                            setData('category_id', '');
                            setData('sub_category_id', '');
                        }}
                    />
                </FormField>
                <FormField id="category_id" label="Category" error={errors.category_id}>
                    <OptionSelect
                        id="category_id"
                        value={data.category_id}
                        options={categories}
                        placeholder={data.department_id ? 'Choose…' : 'Choose a department first'}
                        disabled={!data.department_id}
                        invalid={!!errors.category_id}
                        onChange={(value) => {
                            setData('category_id', value);
                            setData('sub_category_id', '');
                        }}
                    />
                </FormField>
                <FormField id="sub_category_id" label="Sub-category" optional error={errors.sub_category_id}>
                    <OptionSelect
                        id="sub_category_id"
                        value={data.sub_category_id}
                        options={subs}
                        none="None"
                        disabled={subs.length === 0}
                        invalid={!!errors.sub_category_id}
                        onChange={(value) => setData('sub_category_id', value)}
                    />
                </FormField>
            </FormGrid>
        </FormSection>
    );
}

/** Every shop's sell price (shop prices are on the prices screen), cost, VAT, trade prices; margin as you type. */
export function PricingSection({ data, setData, errors, options }: SectionProps) {
    const vat = options.vatRates.find((v) => v.value === data.vat_rate_id);
    const margin = marginPercent(data.sell_price, data.cost_price, vat?.percentage);

    return (
        <FormSection title={taxText('Price and VAT')} description={taxText('The price every shop charges, including VAT. Shop-by-shop prices are set separately.')}>
            <FormGrid columns={3}>
                <FormField id="sell_price" label="Sell price" help={taxText('Including VAT.')} error={errors.sell_price}>
                    <MoneyInput id="sell_price" required value={data.sell_price} invalid={!!errors.sell_price} onChange={(e) => setData('sell_price', e.target.value)} />
                </FormField>
                <FormField
                    id="cost_price"
                    label="Cost price"
                    help={margin === null ? taxText('Excluding VAT, up to 4 decimal places.') : <span className="tabular-nums">Margin {margin}% after {taxName()}.</span>}
                    error={errors.cost_price}
                >
                    <MoneyInput id="cost_price" places={4} required value={data.cost_price} invalid={!!errors.cost_price} onChange={(e) => setData('cost_price', e.target.value)} />
                </FormField>
                <FormField id="vat_rate_id" label={taxText('VAT rate')} error={errors.vat_rate_id}>
                    <OptionSelect id="vat_rate_id" value={data.vat_rate_id} options={options.vatRates} invalid={!!errors.vat_rate_id} onChange={(value) => setData('vat_rate_id', value)} />
                </FormField>
                <FormField id="trade_price" label="Trade price" optional help="For trade customers." error={errors.trade_price}>
                    <MoneyInput id="trade_price" value={data.trade_price} invalid={!!errors.trade_price} onChange={(e) => setData('trade_price', e.target.value)} />
                </FormField>
                <FormField id="pmp_price" label="Price-marked (PMP)" optional help="The price printed on the pack." error={errors.pmp_price}>
                    <MoneyInput id="pmp_price" value={data.pmp_price} invalid={!!errors.pmp_price} onChange={(e) => setData('pmp_price', e.target.value)} />
                </FormField>
            </FormGrid>
        </FormSection>
    );
}

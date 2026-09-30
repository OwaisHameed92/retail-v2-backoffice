import { CheckField } from '@/components/app/setup/fields';
import { type SupplierFormData, type SupplierFormProps } from '@/components/app/setup/types';
import { FormCard, FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Toggle } from '@/components/ui/toggle';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

const BLANK: SupplierFormData = {
    name: '',
    code: '',
    is_active: true,
    contact_name: '',
    phone: '',
    email: '',
    address_line1: '',
    address_line2: '',
    town: '',
    postcode: '',
    account_number: '',
    vat_number: '',
    terms_kind: 'onDelivery',
    payment_terms_days: '0',
    default_lead_days: '1',
    minimum_order_value: '',
    order_method: 'phone',
    delivery_days: [],
    notes: '',
};

type TextKey = { [K in keyof SupplierFormData]: SupplierFormData[K] extends string ? K : never }[keyof SupplierFormData];

export default function SupplierForm({ supplier, options, canEdit }: SupplierFormProps) {
    const editing = supplier !== null;
    const { data, setData, post, put, processing, errors } = useForm<SupplierFormData>(supplier ? { ...BLANK, ...supplier } : BLANK);
    const title = editing ? `Edit ${supplier.name}` : 'Add supplier';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('app.suppliers.update', supplier.id), { preserveScroll: true });
        } else {
            post(route('app.suppliers.store'), { preserveScroll: true });
        }
    };

    const text = (
        key: TextKey,
        label: string,
        props: { optional?: boolean; help?: string; type?: string; className?: string; upper?: boolean } = {},
    ) => (
        <FormField id={key} label={label} optional={props.optional} help={props.help} error={errors[key]} className={props.className}>
            <Input
                id={key}
                type={props.type ?? 'text'}
                value={data[key]}
                disabled={!canEdit}
                onChange={(e) => setData(key, props.upper ? e.target.value.toUpperCase() : e.target.value)}
                aria-invalid={errors[key] ? true : undefined}
            />
        </FormField>
    );

    const toggleDay = (day: string, on: boolean) =>
        setData(
            'delivery_days',
            options.days.filter((d) => (d === day ? on : data.delivery_days.includes(d))),
        );

    return (
        <AppLayout>
            <Head title={title} />
            <PageHeader
                title={title}
                status={editing ? <StatusBadge status={supplier.is_active ? 'active' : 'inactive'} /> : undefined}
                description="Every till gets these details at its next sync."
                back={{ href: route('app.suppliers.index'), label: 'Suppliers' }}
            />

            <form onSubmit={submit} className="grid max-w-4xl gap-6" noValidate>
                <FormCard>
                    <FormSection title="Supplier" description="The name and code staff see when they order or book in a delivery.">
                        <FormGrid>
                            {text('name', 'Name')}
                            {text('code', 'Code', {
                                optional: true,
                                help: 'Short code, e.g. BOOK. Left blank, one is made from the name.',
                                upper: true,
                            })}
                            {text('account_number', 'Your account number', { optional: true })}
                            {text('vat_number', 'VAT number', { optional: true, upper: true })}
                        </FormGrid>
                        <CheckField
                            id="is_active"
                            label="Active"
                            help="Inactive suppliers are hidden on the tills but kept on past orders."
                            checked={data.is_active}
                            onChange={(value) => setData('is_active', value)}
                            disabled={!canEdit}
                        />
                    </FormSection>

                    <FormSection title="Contact" description="How you reach them.">
                        <FormGrid>
                            {text('contact_name', 'Contact name', { optional: true })}
                            {text('phone', 'Phone', { optional: true, type: 'tel' })}
                            {text('email', 'Email', { optional: true, type: 'email', className: 'sm:col-span-2' })}
                            {text('address_line1', 'Address', { optional: true })}
                            {text('address_line2', 'Address line 2', { optional: true })}
                            {text('town', 'Town', { optional: true })}
                            {text('postcode', 'Postcode', { optional: true, upper: true })}
                        </FormGrid>
                    </FormSection>

                    <FormSection title="Ordering and payment" description="Used when a till suggests or sends an order.">
                        <FormGrid>
                            <FormField id="order_method" label="How you order" error={errors.order_method}>
                                <Select value={data.order_method} onValueChange={(v) => setData('order_method', v)} disabled={!canEdit}>
                                    <SelectTrigger id="order_method">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.orderMethods.map((o) => (
                                            <SelectItem key={o.value} value={o.value}>
                                                {o.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            {text('minimum_order_value', 'Minimum order (£)', { optional: true, help: 'Leave blank for no minimum.' })}
                            <FormField id="terms_kind" label="Payment terms" error={errors.terms_kind}>
                                <Select value={data.terms_kind} onValueChange={(v) => setData('terms_kind', v)} disabled={!canEdit}>
                                    <SelectTrigger id="terms_kind">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.termsKinds.map((o) => (
                                            <SelectItem key={o.value} value={o.value}>
                                                {o.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            {data.terms_kind === 'netDays' && text('payment_terms_days', 'Days to pay', { type: 'number' })}
                            {text('default_lead_days', 'Days from order to delivery', { type: 'number', optional: true })}
                        </FormGrid>
                        <FormField id="delivery_days" label="Delivery days" optional error={errors.delivery_days}>
                            <div className="flex flex-wrap gap-2" role="group" aria-label="Delivery days">
                                {options.days.map((day) => (
                                    <Toggle
                                        key={day}
                                        variant="outline"
                                        size="sm"
                                        pressed={data.delivery_days.includes(day)}
                                        onPressedChange={(on) => toggleDay(day, on)}
                                        disabled={!canEdit}
                                        className="data-[state=on]:bg-primary-soft data-[state=on]:text-accent-foreground data-[state=on]:border-primary/40 capitalize"
                                    >
                                        {day.slice(0, 3)}
                                    </Toggle>
                                ))}
                            </div>
                        </FormField>
                    </FormSection>

                    <FormSection title="Notes" description="Anything staff should know, such as cut-off times.">
                        <FormField id="notes" label="Notes" optional error={errors.notes}>
                            <Textarea id="notes" rows={3} value={data.notes} disabled={!canEdit} onChange={(e) => setData('notes', e.target.value)} />
                        </FormField>
                    </FormSection>

                    <div className="bg-subtle flex flex-col-reverse gap-2 px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                        <Button variant="outline" asChild>
                            <Link href={route('app.suppliers.index')}>{canEdit ? 'Cancel' : 'Back'}</Link>
                        </Button>
                        {canEdit && (
                            <Button type="submit" disabled={processing}>
                                {processing && <LoaderCircle className="size-4 animate-spin" />}
                                {editing ? 'Save changes' : 'Add supplier'}
                            </Button>
                        )}
                    </div>
                </FormCard>
            </form>
        </AppLayout>
    );
}

import { MoneyInput } from '@/components/app/products/fields';
import { CheckField } from '@/components/app/setup/fields';
import { FormCard, FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';
import { type CustomerDetails, type CustomerFormData } from './types';

export const BLANK_CUSTOMER: CustomerFormData = {
    name: '',
    phone: '',
    email: '',
    address: '',
    dob: '',
    card_no: '',
    credit_limit: '0.00',
    tier: '',
    notes: '',
    is_active: true,
};

type TextKey = Exclude<keyof CustomerFormData, 'is_active'>;

/**
 * The customer's portal-owned details (name, contact, card, credit limit, tier, notes, active). Balance and points
 * are not here: they come from the ledger the tills write.
 */
export function CustomerForm({ customer, canEdit }: { customer: CustomerDetails | null; canEdit: boolean }) {
    const editing = customer !== null;
    const initial: CustomerFormData = customer
        ? {
              name: customer.name,
              phone: customer.phone,
              email: customer.email,
              address: customer.address,
              dob: customer.dob,
              card_no: customer.card_no,
              credit_limit: customer.credit_limit,
              tier: customer.tier,
              notes: customer.notes,
              is_active: customer.is_active,
          }
        : BLANK_CUSTOMER;
    const { data, setData, post, put, processing, errors, isDirty, reset, setDefaults } = useForm<CustomerFormData>(initial);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('app.customers.update', customer.id), { preserveScroll: true, onSuccess: () => setDefaults() });
        } else {
            post(route('app.customers.store'));
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

    return (
        <form onSubmit={submit} className="grid max-w-4xl gap-6" noValidate>
            <FormCard>
                <FormSection title="Customer" description="How staff find them at the till: by name, phone or by scanning their card.">
                    <FormGrid>
                        {text('name', 'Name')}
                        {text('card_no', 'Card number', {
                            optional: true,
                            help: 'The loyalty or account card number. Unique in your business.',
                            upper: true,
                        })}
                        {text('phone', 'Phone', { optional: true, type: 'tel' })}
                        {text('email', 'Email', {
                            optional: true,
                            type: 'email',
                            help: 'Used for statements. Marketing needs their consent, given at a till.',
                        })}
                        {text('address', 'Address', { optional: true, className: 'sm:col-span-2' })}
                        {text('dob', 'Date of birth', { optional: true, type: 'date' })}
                        {text('tier', 'Loyalty tier', { optional: true, help: 'For example Silver or Gold.' })}
                    </FormGrid>
                    <CheckField
                        id="is_active"
                        label="Active"
                        help="Inactive customers cannot be picked at the till. Their account history is kept."
                        checked={data.is_active}
                        onChange={(value) => setData('is_active', value)}
                        disabled={!canEdit}
                    />
                </FormSection>

                <FormSection title="Account" description="Customers with a credit limit can buy on account at any of your shops.">
                    <FormGrid>
                        <FormField id="credit_limit" label="Credit limit" help="0 means no buying on account." error={errors.credit_limit}>
                            <MoneyInput
                                id="credit_limit"
                                value={data.credit_limit}
                                disabled={!canEdit}
                                invalid={Boolean(errors.credit_limit)}
                                onChange={(e) => setData('credit_limit', e.target.value)}
                            />
                        </FormField>
                    </FormGrid>
                </FormSection>

                <FormSection title="Notes" description="Seen by staff at the till.">
                    <FormField id="notes" label="Notes" optional error={errors.notes}>
                        <Textarea id="notes" rows={3} value={data.notes} disabled={!canEdit} onChange={(e) => setData('notes', e.target.value)} />
                    </FormField>
                </FormSection>

                {canEdit && (
                    <div className="bg-subtle flex flex-col-reverse gap-2 px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                        {editing ? (
                            <Button type="button" variant="outline" disabled={!isDirty || processing} onClick={() => reset()}>
                                Discard changes
                            </Button>
                        ) : (
                            <Button variant="outline" asChild>
                                <Link href={route('app.customers.index')}>Cancel</Link>
                            </Button>
                        )}
                        <Button type="submit" disabled={processing || (editing && !isDirty)}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            {editing ? 'Save changes' : 'Add customer'}
                        </Button>
                    </div>
                )}
            </FormCard>
        </form>
    );
}

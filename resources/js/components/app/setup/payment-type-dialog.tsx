import { DialogForm } from '@/components/admin/leads/dialog-form';
import { FormField } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';
import { CheckField } from './fields';
import { TENDER_FLAGS, type PaymentTypeRow, type TenderFlag } from './types';

type Data = { name: string; position: string } & Record<TenderFlag, boolean>;

const KIND: { flag: TenderFlag; label: string; help: string }[] = [
    { flag: 'is_cash', label: 'Cash', help: 'Counted in the drawer; the till gives change.' },
    { flag: 'is_card', label: 'Card', help: 'Paid on the card terminal.' },
    { flag: 'is_voucher', label: 'Voucher', help: 'Gift or store credit vouchers.' },
    { flag: 'is_points', label: 'Loyalty points', help: "Paid with a customer's points." },
    { flag: 'is_account', label: 'Customer account', help: "Added to the customer's account to pay later." },
    { flag: 'is_drs_refund', label: 'Deposit return', help: 'Pays out a bottle and can deposit (DRS).' },
];

const BEHAVIOUR: { flag: TenderFlag; label: string; help?: string }[] = [
    { flag: 'show_on_payment', label: 'Show when taking payment' },
    { flag: 'show_on_refund', label: 'Show when giving a refund' },
    { flag: 'show_on_customer_payment', label: 'Show when a customer pays their account' },
    { flag: 'opens_drawer', label: 'Opens the cash drawer' },
    { flag: 'is_active', label: 'Active', help: 'Inactive types are hidden on the tills but kept on past sales.' },
];

function initial(row: PaymentTypeRow | null): Data {
    const flags = Object.fromEntries(TENDER_FLAGS.map((f) => [f, row ? row[f] : f === 'is_active' || f === 'show_on_payment'])) as Record<
        TenderFlag,
        boolean
    >;

    return { name: row?.name ?? '', position: row ? String(row.position) : '', ...flags };
}

/** Add or edit a payment type in a dialog; the list reloads with a toast. */
export function PaymentTypeDialog({ row, open, onOpenChange }: { row: PaymentTypeRow | null; open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm<Data>(initial(row));
    const { data, setData, errors, processing } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
        if (row) {
            form.put(route('app.payment-types.update', row.id), options);
        } else {
            form.post(route('app.payment-types.store'), options);
        }
    };

    return (
        <DialogForm
            open={open}
            onOpenChange={onOpenChange}
            title={row ? `Edit ${row.name}` : 'Add payment type'}
            description="A button on the till's pay screen. Every till gets it at its next sync."
            submitLabel={row ? 'Save changes' : 'Add payment type'}
            processing={processing}
            onSubmit={submit}
        >
            <div className="grid gap-4 sm:grid-cols-[1fr_7rem]">
                <FormField id="pt-name" label="Name on the button" error={errors.name}>
                    <Input
                        id="pt-name"
                        value={data.name}
                        maxLength={40}
                        onChange={(e) => setData('name', e.target.value)}
                        aria-invalid={errors.name ? true : undefined}
                    />
                </FormField>
                <FormField id="pt-position" label="Order" optional error={errors.position}>
                    <Input id="pt-position" type="number" min={0} value={data.position} onChange={(e) => setData('position', e.target.value)} />
                </FormField>
            </div>
            <fieldset className="grid gap-3">
                <legend className="mb-2 text-sm font-semibold">What it is</legend>
                <div className="grid gap-3 sm:grid-cols-2">
                    {KIND.map(({ flag, label, help }) => (
                        <CheckField key={flag} id={`pt-${flag}`} label={label} help={help} checked={data[flag]} onChange={(v) => setData(flag, v)} />
                    ))}
                </div>
            </fieldset>
            <fieldset className="grid gap-3">
                <legend className="mb-2 text-sm font-semibold">On the till</legend>
                {BEHAVIOUR.map(({ flag, label, help }) => (
                    <CheckField key={flag} id={`pt-${flag}`} label={label} help={help} checked={data[flag]} onChange={(v) => setData(flag, v)} />
                ))}
                {errors.is_active && <p className="text-destructive text-sm">{errors.is_active}</p>}
            </fieldset>
        </DialogForm>
    );
}

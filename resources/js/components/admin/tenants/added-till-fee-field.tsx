import { formatPence, toPence } from '@/components/admin/billing/money';
import { MoneyInput } from '@/components/admin/plans/plan-form-fields';
import { Field } from '@/components/admin/tenants/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { byHand, manualMethodsText } from '@/lib/billing-collection';
import { taxName, taxText } from '@/lib/country';
import { usePage } from '@inertiajs/react';
import { Receipt } from 'lucide-react';

/** AddedTillFee::for (P11): the setup fee of tills added to this business. */
export interface AddedTillFeeOptions {
    applies: boolean;
    perTillFee: string;
    tills: number;
    covered: number;
    vatRate: string | null;
    canEdit: boolean;
}

/** How many of `adding` new tills pay a setup fee (tills the business already paid for are never charged again). */
export function chargeableTills(options: AddedTillFeeOptions | undefined, adding: number): number {
    if (!options?.applies || adding <= 0) {
        return 0;
    }

    return Math.min(adding, Math.max(0, options.tills + adding - options.covered));
}

interface AddedTillFeeFieldProps {
    id: string;
    /** Tills this dialog adds. */
    adding: number;
    value: string;
    onChange: (value: string) => void;
    error?: string;
}

/**
 * P11, per-till setup fee plans: the setup fee invoice raised for the tills being added (Add till / Add branch).
 * Pre-filled with the per-till fee × tills; billing admins may change it or enter 0 to waive it. Hidden when the
 * plan charges the setup fee once per business, or the first setup fee is not invoiced yet (it will cover them).
 */
export function AddedTillFeeField({ id, adding, value, onChange, error }: AddedTillFeeFieldProps) {
    const options = usePage<{ tillSetupFee?: AddedTillFeeOptions }>().props.tillSetupFee;
    const count = chargeableTills(options, adding);

    if (!options || count === 0) {
        return null;
    }

    const each = toPence(options.perTillFee) ?? 0;
    const defaultPence = each * count;
    const amountPence = value.trim() === '' ? defaultPence : (toPence(value) ?? defaultPence);
    const rate = options.vatRate === null ? 0 : Math.round(Number(options.vatRate) * 100);
    const gross = amountPence + Math.round((amountPence * rate) / 10000);
    const what = `${formatPence(each)} per till × ${count} ${count === 1 ? 'till' : 'tills'}`;
    const pays = byHand('cash, card or bank transfer', manualMethodsText());

    if (!options.canEdit) {
        return (
            <Alert>
                <Receipt className="size-4" />
                <AlertDescription>
                    A setup fee invoice of {formatPence(gross)}
                    {rate > 0 ? ` incl. ${taxName()}` : ''} ({what}) is raised for {count === 1 ? 'this till' : 'these tills'}, paid by {pays}.
                    {count === 1 ? ' It stays' : ' They stay'} on the trial until it is paid.
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <Field
            id={id}
            label={taxText(count === 1 ? 'Setup fee for this till (before VAT)' : 'Setup fee for these tills (before VAT)')}
            optional
            error={error}
            hint={`Invoiced now: ${formatPence(gross)}${rate > 0 ? ` incl. ${taxName()}` : ''}. Empty = ${what}; 0 = no charge. Paid by ${pays}; until then ${count === 1 ? 'the till stays' : 'the tills stay'} on the trial.`}
        >
            <MoneyInput
                id={id}
                placeholder={(defaultPence / 100).toFixed(2)}
                value={value}
                invalid={!!error}
                onChange={(event) => onChange(event.target.value)}
            />
        </Field>
    );
}

export default AddedTillFeeField;

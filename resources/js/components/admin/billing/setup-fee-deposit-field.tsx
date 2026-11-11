import { formatPence, toPence } from '@/components/admin/billing/money';
import { MoneyInput } from '@/components/admin/plans/plan-form-fields';
import { Field } from '@/components/admin/tenants/field';
import { taxName, taxText } from '@/lib/country';

/**
 * Owner 2026-11-11: what a deposit means for the setup fee, worked in pence the way the server splits it
 * (SetupFee::schedule): the deposit is part 1; the rest of the VAT-inclusive total is split equally across the other
 * parts (the last part takes the pennies), each part's net worked back from its gross. Shown before VAT, as staff
 * quote it: "£350.00 + VAT now, then 11 × £68.00 + VAT". `problem` mirrors the server's checks (it decides).
 */
export function depositPreview(feeNet: string, deposit: string, instalments: number, vatRate: string | null): { text: string; problem: boolean } | null {
    const fee = toPence(feeNet);
    const first = toPence(deposit);

    if (deposit.trim() === '' || fee === null || first === null || !Number.isInteger(instalments)) {
        return null;
    }

    if (first <= 0) {
        return { text: `The deposit must be more than ${formatPence(0)}.`, problem: true };
    }

    if (first >= fee) {
        return { text: `The deposit must be less than the setup fee (${formatPence(fee)} before ${taxName()}).`, problem: true };
    }

    const rate = vatRate === null ? 0 : Math.round(Number(vatRate) * 100);
    const vatOn = (pence: number) => Math.round((pence * rate) / 10000);
    const netOf = (gross: number) => (rate === 0 ? gross : Math.round((gross * 10000) / (10000 + rate)));
    const plus = rate === 0 ? '' : ` + ${taxName()}`;
    const others = instalments - 1;
    const rest = fee + vatOn(fee) - (first + vatOn(first));
    const each = Math.round(rest / others);
    const last = rest - each * (others - 1);

    if (each <= 0 || last <= 0) {
        return { text: `The deposit leaves too little to split into ${others} more instalments.`, problem: true };
    }

    const then = others === 1 ? `${formatPence(netOf(last))}${plus}` : `${others} × ${formatPence(netOf(each))}${plus}`;
    const pennies = others > 1 && last !== each ? ` (the last ${formatPence(netOf(last))}${plus})` : '';

    return { text: `${formatPence(first)}${plus} now, then ${then}${pennies}`, problem: false };
}

interface SetupFeeDepositFieldProps {
    value: string;
    onChange: (value: string) => void;
    error?: string;
    /** The setup fee (net) the deposit comes out of: the agreed fee, else the plan's. */
    feeNet: string;
    instalments: number;
    vatRate: string | null;
    disabled?: boolean;
}

/** Payment settings: "First instalment (deposit)", with a live preview of the instalments it makes. */
export function SetupFeeDepositField({ value, onChange, error, feeNet, instalments, vatRate, disabled = false }: SetupFeeDepositFieldProps) {
    const preview = depositPreview(feeNet, value, instalments, vatRate);
    const equal = `Empty = ${instalments} equal instalments.`;

    return (
        <Field
            id="dd-setup-fee-deposit"
            label={taxText('First instalment (deposit, before VAT)')}
            optional
            error={error}
            hint={
                <span className="grid gap-1">
                    {preview && !error && (
                        <span
                            className={preview.problem ? 'text-destructive' : 'text-foreground font-medium tabular-nums'}
                            aria-live="polite"
                            data-testid="deposit-preview"
                        >
                            {preview.text}
                        </span>
                    )}
                    <span>
                        {disabled
                            ? 'Already invoiced, so it cannot change.'
                            : `The first payment; the rest of the setup fee is split equally across the other ${instalments - 1} ${instalments - 1 === 1 ? 'instalment' : 'instalments'}. ${equal}`}
                    </span>
                </span>
            }
        >
            <MoneyInput
                id="dd-setup-fee-deposit"
                placeholder="0.00"
                value={value}
                invalid={!!error}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
            />
        </Field>
    );
}

export default SetupFeeDepositField;

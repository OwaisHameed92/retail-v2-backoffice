import { formatPence, toPence } from '@/components/admin/billing/money';
import { MoneyInput } from '@/components/admin/plans/plan-form-fields';
import { Field } from '@/components/admin/tenants/field';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

/** OnboardingBilling::options (module 1.13): who may take money, each plan's setup fee, VAT and the deadline. */
export interface OnboardingBillingOptions {
    canRecord: boolean;
    /** Plan id → setup fee (net, decimal string). */
    setupFees: Record<string, string>;
    vatRate: string | null;
    deadlineDays: number;
    methods: { value: string; label: string }[];
}

export type UpfrontPaymentValue = {
    upfront_record: boolean;
    upfront_amount: string;
    upfront_method: string;
    upfront_reference: string;
};

export const emptyUpfront: UpfrontPaymentValue = { upfront_record: false, upfront_amount: '', upfront_method: 'cash', upfront_reference: '' };

/** "£238.80 incl. VAT" for a net amount, worked in pence. */
function withVat(net: string, vatRate: string | null): string | null {
    const pence = toPence(net);
    if (pence === null) {
        return null;
    }

    const rate = vatRate === null ? 0 : Math.round(Number(vatRate) * 100);
    const vat = Math.round((pence * rate) / 10000);

    return vatRate === null || rate === 0 ? formatPence(pence) : `${formatPence(pence + vat)} incl. VAT`;
}

interface UpfrontPaymentFieldsProps {
    value: UpfrontPaymentValue;
    onChange: <K extends keyof UpfrontPaymentValue>(key: K, value: UpfrontPaymentValue[K]) => void;
    errors: Partial<Record<keyof UpfrontPaymentValue, string>>;
    options: OnboardingBillingOptions;
    /** The plan's setup fee (net) the amount defaults to. */
    planFee: string;
    /** Show the "Took an upfront payment" checkbox (onboarding); off on the Billing tab dialog. */
    toggle?: boolean;
    idPrefix?: string;
}

/**
 * The upfront payment staff took (module 1.13): the setup fee (the plan's unless changed; 0 = nothing to pay), cash
 * or bank transfer, and a reference. Used by the tenant wizard, the trial approval and the Billing tab.
 */
export function UpfrontPaymentFields({ value, onChange, errors, options, planFee, toggle = true, idPrefix = 'upfront' }: UpfrontPaymentFieldsProps) {
    const shown = !toggle || value.upfront_record;
    const amount = value.upfront_amount.trim() === '' ? planFee : value.upfront_amount;
    const total = withVat(amount, options.vatRate);

    return (
        <div className="grid gap-4">
            {toggle && (
                <div className="flex items-start gap-3">
                    <Checkbox
                        id={`${idPrefix}-record`}
                        checked={value.upfront_record}
                        onCheckedChange={(checked) => onChange('upfront_record', checked === true)}
                    />
                    <div className="grid gap-1">
                        <Label htmlFor={`${idPrefix}-record`}>The business paid upfront</Label>
                        <p className="text-muted-foreground text-sm">
                            Records the setup fee as a paid invoice now. Leave unticked to collect it by Direct Debit once the owner sets it up.
                        </p>
                    </div>
                </div>
            )}

            {shown && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        id={`${idPrefix}-amount`}
                        label="Setup fee (before VAT)"
                        optional
                        error={errors.upfront_amount}
                        hint={
                            total ? `Received: ${total}. Empty = the plan’s fee; 0 = nothing to pay.` : 'Empty = the plan’s fee; 0 = nothing to pay.'
                        }
                    >
                        <MoneyInput
                            id={`${idPrefix}-amount`}
                            placeholder={planFee}
                            value={value.upfront_amount}
                            invalid={!!errors.upfront_amount}
                            onChange={(event) => onChange('upfront_amount', event.target.value)}
                        />
                    </Field>
                    <Field id={`${idPrefix}-method`} label="Paid by" error={errors.upfront_method}>
                        <Select value={value.upfront_method} onValueChange={(method) => onChange('upfront_method', method)}>
                            <SelectTrigger id={`${idPrefix}-method`}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {options.methods.map((method) => (
                                    <SelectItem key={method.value} value={method.value}>
                                        {method.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field id={`${idPrefix}-reference`} label="Reference" optional error={errors.upfront_reference} className="sm:col-span-2">
                        <Input
                            id={`${idPrefix}-reference`}
                            placeholder="Receipt number or bank reference"
                            value={value.upfront_reference}
                            onChange={(event) => onChange('upfront_reference', event.target.value)}
                        />
                    </Field>
                </div>
            )}
        </div>
    );
}

export default UpfrontPaymentFields;

/** The "Billing" copy above the upfront fields at onboarding: the Direct Debit the owner sets up, and who can take money. */
export function OnboardingBillingNote({ options }: { options: OnboardingBillingOptions }) {
    return (
        <p className="text-muted-foreground text-sm">
            The owner sets up their Direct Debit from Billing in the portal (the welcome email links to it) within {options.deadlineDays}{' '}
            {options.deadlineDays === 1 ? 'day' : 'days'}, or the tills lock until they do. Not needed when nothing is charged each cycle.
            {!options.canRecord && ' Only owner and accounts staff can record an upfront payment; the setup fee is then collected by Direct Debit.'}
        </p>
    );
}

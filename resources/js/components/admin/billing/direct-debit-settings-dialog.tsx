import { formatPence, toPence } from '@/components/admin/billing/money';
import { type BillingMode, type CompanyRef, type DirectDebitData, type SetupFeeMethod } from '@/components/admin/billing/types';
import { MoneyInput } from '@/components/admin/plans/plan-form-fields';
import { Field } from '@/components/admin/tenants/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface DirectDebitSettingsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    company: CompanyRef;
    directDebit: DirectDebitData;
}

type FormData = {
    billing_mode: BillingMode;
    setup_fee_override: string;
    setup_fee_method: SetupFeeMethod;
    setup_fee_instalments: string;
};

/** How the business pays (upfront or Direct Debit) and its setup fee: amount, how it is paid, instalments. */
export function DirectDebitSettingsDialog(props: DirectDebitSettingsDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <SettingsBody {...props} />}
        </Dialog>
    );
}

function SettingsBody({ onOpenChange, company, directDebit }: DirectDebitSettingsDialogProps) {
    const { setupFee, options } = directDebit;
    const locked = setupFee.invoicedAt !== null;
    const { data, setData, put, processing, errors } = useForm<FormData>({
        billing_mode: directDebit.mode,
        setup_fee_override: setupFee.override ?? '',
        setup_fee_method: setupFee.method,
        setup_fee_instalments: String(setupFee.instalments),
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        put(route('admin.billing.tenants.direct-debit.settings', company.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    const directDebitMode = data.billing_mode === 'directDebit';

    return (
        <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-xl" onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>How {company.name} pays</DialogTitle>
                    <DialogDescription>Upfront: each period paid by hand. Direct Debit: GoCardless collects the subscription and the setup fee.</DialogDescription>
                </DialogHeader>

                <Field id="dd-mode" label="Billing mode" error={errors.billing_mode}>
                    <Select value={data.billing_mode} onValueChange={(value) => setData('billing_mode', value as BillingMode)}>
                        <SelectTrigger id="dd-mode">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {options.modes.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        id="dd-setup-fee"
                        label="Setup fee (before VAT)"
                        optional
                        error={errors.setup_fee_override}
                        hint={locked ? 'Already invoiced, so it cannot change.' : `Empty uses the plan’s fee (${formatPence(toPence(setupFee.plan) ?? 0)}).`}
                    >
                        <MoneyInput
                            id="dd-setup-fee"
                            placeholder={setupFee.plan}
                            value={data.setup_fee_override}
                            invalid={!!errors.setup_fee_override}
                            disabled={locked}
                            onChange={(event) => setData('setup_fee_override', event.target.value)}
                        />
                    </Field>
                    <Field id="dd-instalments" label="Instalments" error={errors.setup_fee_instalments} hint="1 = paid at once; more = monthly payments.">
                        <Input
                            id="dd-instalments"
                            type="number"
                            min={1}
                            max={options.maxInstalments}
                            disabled={locked}
                            value={data.setup_fee_instalments}
                            onChange={(event) => setData('setup_fee_instalments', event.target.value)}
                            aria-invalid={!!errors.setup_fee_instalments}
                        />
                    </Field>
                </div>

                <Field
                    id="dd-setup-method"
                    label="Setup fee paid by"
                    error={errors.setup_fee_method}
                    hint={
                        directDebitMode
                            ? 'Direct Debit: collected on the mandate as soon as it is set up. Cash or bank transfer: invoiced now, recorded with Record payment.'
                            : 'Upfront customers pay the setup fee by cash or bank transfer.'
                    }
                >
                    <Select value={directDebitMode ? data.setup_fee_method : 'manual'} onValueChange={(value) => setData('setup_fee_method', value as SetupFeeMethod)} disabled={locked || !directDebitMode}>
                        <SelectTrigger id="dd-setup-method">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {options.setupFeeMethods.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Save changes
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

export default DirectDebitSettingsDialog;

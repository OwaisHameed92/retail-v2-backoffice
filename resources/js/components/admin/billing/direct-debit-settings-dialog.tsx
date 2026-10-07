import { formatPence, toPence } from '@/components/admin/billing/money';
import { type BillingMode, type CompanyRef, type DirectDebitData } from '@/components/admin/billing/types';
import { MoneyInput } from '@/components/admin/plans/plan-form-fields';
import { Field } from '@/components/admin/tenants/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { byHand, manualCollection, manualMethodsText } from '@/lib/billing-collection';
import { taxText } from '@/lib/country';
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
    setup_fee_instalments: string;
    till_setup_fee_override: string;
};

/** How the business pays its recurring fee (Direct Debit, or upfront as an exception) and its setup fee: amount, instalments. */
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
        setup_fee_instalments: String(setupFee.instalments),
        till_setup_fee_override: setupFee.tillFeeOverride ?? '',
    });
    const covered = setupFee.coveredTills ?? setupFee.tills;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        put(route('admin.billing.tenants.direct-debit.settings', company.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-xl" onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>How {company.name} pays</DialogTitle>
                    {manualCollection() ? (
                        <DialogDescription>
                            Every monthly or yearly invoice is paid by hand ({manualMethodsText()}). Set the setup fee here.
                        </DialogDescription>
                    ) : (
                        <DialogDescription>
                            Direct Debit (the normal way): GoCardless collects the monthly or yearly fee. Upfront: each period paid by hand, as an
                            exception.
                        </DialogDescription>
                    )}
                </DialogHeader>

                {!manualCollection() && (
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
                )}

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field
                        id="dd-setup-fee"
                        label={taxText('Setup fee (upfront, before VAT)')}
                        optional
                        error={errors.setup_fee_override}
                        hint={
                            locked
                                ? 'Already invoiced, so it cannot change.'
                                : `Empty uses the plan’s fee (${formatPence(toPence(setupFee.plan) ?? 0)}).`
                        }
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
                    <Field
                        id="dd-instalments"
                        label="Instalments"
                        error={errors.setup_fee_instalments}
                        hint="1 = paid at once; more = monthly payments by hand."
                    >
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

                {setupFee.perTill && (
                    <div className="grid gap-2 rounded-lg border p-4">
                        <Field
                            id="dd-till-setup-fee"
                            label={taxText('Setup fee per added till (before VAT)')}
                            optional
                            error={errors.till_setup_fee_override}
                            hint={`This plan charges the setup fee for each till. Each till added later is invoiced this amount (it can still be changed when the till is added). Empty uses the plan’s ${formatPence(toPence(setupFee.tillFeePlan) ?? 0)}.`}
                        >
                            <MoneyInput
                                id="dd-till-setup-fee"
                                placeholder={setupFee.tillFeePlan}
                                value={data.till_setup_fee_override}
                                invalid={!!errors.till_setup_fee_override}
                                onChange={(event) => setData('till_setup_fee_override', event.target.value)}
                            />
                        </Field>
                        <p className="text-muted-foreground text-sm tabular-nums">
                            {Math.min(covered, setupFee.tills)} of {setupFee.tills} {setupFee.tills === 1 ? 'till is' : 'tills are'} covered by a
                            setup fee
                            {setupFee.coveredTills === null ? ' (every till the business has)' : ''}
                            {setupFee.heldTills > 0
                                ? `; ${setupFee.heldTills} ${setupFee.heldTills === 1 ? 'till waits' : 'tills wait'} for its setup fee invoice to be paid.`
                                : '.'}
                        </p>
                    </div>
                )}

                <p className="text-muted-foreground text-sm">
                    The setup fee is always paid by hand: {byHand('cash, card or bank transfer', manualMethodsText())}, recorded with{' '}
                    <span className="font-medium">Record setup fee payment</span>.{byHand(' It is never taken by Direct Debit.', '')}
                </p>

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

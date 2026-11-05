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
    /** Owner 2026-11-05: "byHand" (the default) or "directDebit" (a per-business exception). */
    setup_fee_collection: 'byHand' | 'directDebit';
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
    const { data, setData, put, processing, errors, transform } = useForm<FormData>({
        billing_mode: directDebit.mode,
        setup_fee_override: setupFee.override ?? '',
        setup_fee_instalments: String(setupFee.instalments),
        till_setup_fee_override: setupFee.tillFeeOverride ?? '',
        setup_fee_collection: setupFee.byDirectDebit ? 'directDebit' : 'byHand',
    });
    const covered = setupFee.coveredTills ?? setupFee.tills;
    // The choice is offered only on a Direct Debit instance with GoCardless connected, for a Direct Debit business.
    const offerDirectDebit = !manualCollection() && setupFee.directDebitAvailable && data.billing_mode === 'directDebit';
    const feeByDirectDebit = offerDirectDebit && data.setup_fee_collection === 'directDebit';

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        transform((form) => ({
            billing_mode: form.billing_mode,
            setup_fee_override: form.setup_fee_override,
            setup_fee_instalments: form.setup_fee_instalments,
            till_setup_fee_override: form.till_setup_fee_override,
            // Sent only where it can be chosen; otherwise the business keeps what it has (by hand unless staff chose).
            ...(setupFee.directDebitAvailable && !manualCollection() ? { setup_fee_by_direct_debit: feeByDirectDebit } : {}),
        }));
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
                        hint={feeByDirectDebit ? '1 = collected at once; more = monthly Direct Debit payments.' : '1 = paid at once; more = monthly payments by hand.'}
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

                {offerDirectDebit ? (
                    <Field
                        id="dd-setup-fee-collection"
                        label="Setup fee"
                        error={(errors as Partial<Record<string, string>>).setup_fee_by_direct_debit}
                        hint={
                            feeByDirectDebit
                                ? 'Exception: GoCardless collects each instalment on its due date from the mandate (once it is set up). An instalment already paid by hand is never collected.'
                                : 'Paid by hand (cash, card or bank transfer), recorded with Record setup fee payment.'
                        }
                    >
                        <Select
                            value={data.setup_fee_collection}
                            onValueChange={(value) => setData('setup_fee_collection', value as FormData['setup_fee_collection'])}
                        >
                            <SelectTrigger id="dd-setup-fee-collection">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="byHand">By hand (default)</SelectItem>
                                <SelectItem value="directDebit">By Direct Debit</SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        The setup fee is paid by hand: {byHand('cash, card or bank transfer', manualMethodsText())}, recorded with{' '}
                        <span className="font-medium">Record setup fee payment</span>.
                        {byHand(
                            setupFee.directDebitAvailable
                                ? ' Collecting it by Direct Debit (an exception) is offered only to a business that pays by Direct Debit.'
                                : ' It is never taken by Direct Debit.',
                            '',
                        )}
                    </p>
                )}

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

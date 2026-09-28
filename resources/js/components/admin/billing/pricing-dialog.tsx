import { type CompanyRef, type DirectDebitData } from '@/components/admin/billing/types';
import { MoneyInput } from '@/components/admin/plans/plan-form-fields';
import { Field } from '@/components/admin/tenants/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface PricingDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    company: CompanyRef;
    pricing: DirectDebitData['pricing'];
}

const PLAN = 'plan';

/** This business's own pricing (module 1.13): per till or per branch and the price per unit; blank = the plan's. */
export function PricingDialog(props: PricingDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <PricingBody {...props} />}
        </Dialog>
    );
}

function PricingBody({ onOpenChange, company, pricing }: PricingDialogProps) {
    const { data, setData, put, processing, errors, transform } = useForm({
        pricing_mode: pricing.override.mode ?? PLAN,
        price_monthly: pricing.override.monthly ?? '',
        price_yearly: pricing.override.yearly ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        transform((form) => ({ ...form, pricing_mode: form.pricing_mode === PLAN ? '' : form.pricing_mode }));
        put(route('admin.billing.tenants.pricing', company.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    const plan = pricing.plan;

    return (
        <DialogContent className="sm:max-w-lg" onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>Pricing for {company.name}</DialogTitle>
                    <DialogDescription>
                        Leave a field blank to use the plan’s
                        {plan ? ` (${plan.name}: ${plan.modeLabel.toLowerCase()}, £${plan.monthly} a month, £${plan.yearly} a year)` : ''}. New
                        invoices and the Direct Debit amount follow it from the next payment.
                    </DialogDescription>
                </DialogHeader>

                <Field id="pricing-mode" label="Charge" error={errors.pricing_mode}>
                    <Select value={data.pricing_mode} onValueChange={(value) => setData('pricing_mode', value)}>
                        <SelectTrigger id="pricing-mode">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={PLAN}>As the plan{plan ? ` (${plan.modeLabel.toLowerCase()})` : ''}</SelectItem>
                            {pricing.options.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="pricing-monthly" label="Monthly price per unit" optional error={errors.price_monthly} hint="Before VAT.">
                        <MoneyInput
                            id="pricing-monthly"
                            placeholder={plan?.monthly}
                            value={data.price_monthly}
                            invalid={!!errors.price_monthly}
                            onChange={(event) => setData('price_monthly', event.target.value)}
                        />
                    </Field>
                    <Field id="pricing-yearly" label="Yearly price per unit" optional error={errors.price_yearly} hint="Before VAT.">
                        <MoneyInput
                            id="pricing-yearly"
                            placeholder={plan?.yearly}
                            value={data.price_yearly}
                            invalid={!!errors.price_yearly}
                            onChange={(event) => setData('price_yearly', event.target.value)}
                        />
                    </Field>
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Save pricing
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

export default PricingDialog;

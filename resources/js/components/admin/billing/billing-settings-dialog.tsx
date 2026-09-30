import { type BillingCycle, type CompanyRef, type TenantBillingData } from '@/components/admin/billing/types';
import { Field } from '@/components/admin/tenants/field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface BillingSettingsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    company: CompanyRef & { legalName?: string | null; address?: string | null };
    billing: Pick<TenantBillingData, 'settings' | 'options' | 'vatEnabled' | 'vatRate'>;
}

type FormData = {
    billing_name: string;
    billing_address: string;
    emails: string;
    cycle: BillingCycle;
    payment_terms_days: string;
    vat_applies: boolean;
};

/** Who invoices go to, the name and address on them, cycle, payment terms and VAT. */
export function BillingSettingsDialog(props: BillingSettingsDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <SettingsBody {...props} />}
        </Dialog>
    );
}

function SettingsBody({ onOpenChange, company, billing }: BillingSettingsDialogProps) {
    const { settings, options, vatEnabled, vatRate } = billing;
    const { data, setData, put, processing, errors, transform } = useForm<FormData>({
        billing_name: settings.billingName ?? '',
        billing_address: settings.billingAddress ?? '',
        emails: settings.emails.join(', '),
        cycle: settings.cycle,
        payment_terms_days: String(settings.paymentTermsDays),
        vat_applies: settings.vatApplies,
    });

    transform((form) => ({
        ...form,
        emails: form.emails
            .split(/[\s,;]+/)
            .map((email) => email.trim())
            .filter(Boolean),
    }));

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        put(route('admin.billing.tenants.settings', company.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    const emailError = errors.emails ?? Object.entries(errors).find(([key]) => key.startsWith('emails.'))?.[1];

    return (
        <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-xl" onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>Billing settings</DialogTitle>
                    <DialogDescription>For {company.name}. Changes apply to invoices issued from now on.</DialogDescription>
                </DialogHeader>

                <Field
                    id="billing-emails"
                    label="Send invoices to"
                    optional
                    error={emailError}
                    hint={settings.recipientsAreOwners && settings.recipients.length > 0 ? `Empty sends them to the owner (${settings.recipients.join(', ')}).` : 'Up to 5 addresses, separated by commas. Empty sends them to the owners.'}
                >
                    <Input id="billing-emails" type="text" inputMode="email" autoComplete="off" value={data.emails} onChange={(event) => setData('emails', event.target.value)} aria-invalid={!!emailError} />
                </Field>

                <Field id="billing-name" label="Name on invoices" optional error={errors.billing_name} hint={`Empty uses “${company.legalName || company.name}”.`}>
                    <Input id="billing-name" maxLength={191} value={data.billing_name} onChange={(event) => setData('billing_name', event.target.value)} />
                </Field>

                <Field id="billing-address" label="Billing address" optional error={errors.billing_address} hint={company.address ? 'Empty uses the business address.' : 'One line per row.'}>
                    <Textarea id="billing-address" rows={3} maxLength={1000} value={data.billing_address} onChange={(event) => setData('billing_address', event.target.value)} placeholder={company.address ?? ''} />
                </Field>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field id="billing-cycle" label="Billing cycle" error={errors.cycle}>
                        <Select value={data.cycle} onValueChange={(value) => setData('cycle', value as BillingCycle)}>
                            <SelectTrigger id="billing-cycle">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {options.cycles.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field id="billing-terms" label="Payment terms (days)" error={errors.payment_terms_days} hint="Days after the invoice date.">
                        <Input
                            id="billing-terms"
                            type="number"
                            min={0}
                            max={90}
                            value={data.payment_terms_days}
                            onChange={(event) => setData('payment_terms_days', event.target.value)}
                            aria-invalid={!!errors.payment_terms_days}
                        />
                    </Field>
                </div>

                <div className="flex items-start gap-3">
                    <Checkbox id="billing-vat" checked={data.vat_applies} onCheckedChange={(checked) => setData('vat_applies', checked === true)} className="mt-0.5" disabled={!vatEnabled} />
                    <div className="grid gap-1">
                        <Label htmlFor="billing-vat">Charge VAT</Label>
                        <p className="text-muted-foreground text-sm">
                            {vatEnabled ? `Adds VAT at ${vatRate} to this business’s invoices.` : 'VAT is switched off for all invoices (we are not VAT registered).'}
                        </p>
                    </div>
                </div>

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

export default BillingSettingsDialog;

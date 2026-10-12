import { formatPence, toPence } from '@/components/admin/billing/money';
import { OnboardingBillingNote, onboardingPlanFee } from '@/components/admin/billing/upfront-payment-fields';
import { ApproveLicenceSection } from '@/components/admin/leads/approve-licence-section';
import { licencePayload, type LicenceFormValues } from '@/components/admin/licences/licence-form-fields';
import { ReviewShops } from '@/components/admin/partners/review-shops';
import { type ReviewFormValues, type ReviewOptions } from '@/components/admin/partners/types';
import { MoneyInput } from '@/components/admin/plans/plan-form-fields';
import { CompanyFields } from '@/components/admin/tenants/company-fields';
import { Field, FormSection, Textarea } from '@/components/admin/tenants/field';
import { ReasonDialog } from '@/components/admin/tenants/reason-dialog';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { FormCard } from '@/components/shared/form-section';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { byHand } from '@/lib/billing-collection';
import { taxName } from '@/lib/country';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useState } from 'react';

type Form = ReviewFormValues & LicenceFormValues & { multi_branch: boolean; max_branches: number };

interface ReviewFormProps {
    requestId: string;
    businessName: string;
    partner: string;
    initial: ReviewFormValues;
    options: ReviewOptions;
}

const statusOptions = [
    { value: 'trial', title: 'Free trial', body: 'Until the setup fee is paid; a setup-only plan then gets its full licence.' },
    { value: 'active', title: 'Active customer', body: 'Already agreed a plan and paying.' },
] as const;

/**
 * The partner request's review form: the new-business wizard's sections, pre-filled from the partner's body and
 * editable, then Approve (creates the business through the normal onboarding) or Reject (with a reason).
 */
export function ReviewForm({ requestId, businessName, partner, initial, options }: ReviewFormProps) {
    const defaults = options.planDefaults[initial.plan_id];
    const { data, setData, post, processing, errors, transform } = useForm<Form>({
        ...initial,
        max_registers: 1,
        kind: 'trial',
        length: '',
        length_unit: '',
        valid_from: '',
        features: defaults?.features ?? [],
        custom_features: false,
        multi_branch: initial.shops.length > 1 || (defaults?.multiBranch ?? false),
        max_branches: Math.max(1, initial.shops.length),
    });
    const [confirming, setConfirming] = useState(false);
    const [rejecting, setRejecting] = useState(false);

    transform((values) => ({ ...values, ...licencePayload(values), max_branches: values.multi_branch ? values.max_branches : 1 }));

    const tills = data.shops.reduce((sum, shop) => sum + shop.tills, 0);
    const planFee = onboardingPlanFee(options.billing, data.plan_id, tills);
    const perTill = toPence(data.setup_fee_per_till);
    const agreedTotal = data.setup_fee_per_till.trim() !== '' && perTill !== null ? formatPence(perTill * tills) : null;
    const errorCount = Object.keys(errors).length;

    const changePlan = (planId: string) =>
        setData((current) => ({
            ...current,
            plan_id: planId,
            features: current.custom_features ? current.features : (options.planDefaults[planId]?.features ?? current.features),
        }));

    const approve = () =>
        new Promise<void>((resolve) => {
            post(route('admin.partner-requests.approve', requestId), {
                preserveScroll: true,
                onFinish: () => resolve(),
                onError: () => setConfirming(false),
            });
        });

    return (
        <form onSubmit={(event) => (event.preventDefault(), setConfirming(true))} noValidate className="grid gap-6">
            {errorCount > 0 && (
                <Alert variant="destructive">
                    <CircleAlert className="size-4" />
                    <AlertDescription>
                        {errors.status ??
                            `${errorCount === 1 ? 'One field needs attention.' : `${errorCount} fields need attention.`} Check the highlighted fields below.`}
                    </AlertDescription>
                </Alert>
            )}

            <FormCard>
                <FormSection title="Business details" description="As the partner sent them. These details are sent to the till.">
                    <CompanyFields
                        data={data}
                        setData={(key, value) => setData(key, value)}
                        errors={errors}
                        businessTypes={options.licenceOptions.businessTypes}
                    />
                </FormSection>

                <FormSection title="Account" description="How the customer starts with us.">
                    <fieldset className="grid grid-cols-1 gap-3 sm:col-span-2 sm:grid-cols-2">
                        <legend className="sr-only">Account status</legend>
                        {statusOptions.map((option) => (
                            <label
                                key={option.value}
                                className={cn(
                                    'focus-within:ring-ring flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors focus-within:ring-2',
                                    data.status === option.value
                                        ? 'border-primary bg-primary-soft ring-primary/20 ring-1'
                                        : 'hover:border-border-strong hover:bg-subtle',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="status"
                                    value={option.value}
                                    checked={data.status === option.value}
                                    onChange={() => setData('status', option.value)}
                                    className="accent-primary mt-1"
                                />
                                <span className="grid gap-0.5">
                                    <span className="text-sm font-medium">{option.title}</span>
                                    <span className="text-muted-foreground text-sm">{option.body}</span>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    <Field
                        id="notes"
                        label="Internal notes"
                        optional
                        hint="Only Switch & Save staff see these."
                        error={errors.notes}
                        className="sm:col-span-2"
                    >
                        <Textarea id="notes" rows={3} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </Field>
                </FormSection>

                <FormSection
                    title="Shops and tills"
                    description="One branch per shop, each with its tills. The first till of each shop is its main till."
                >
                    <ReviewShops
                        shops={data.shops}
                        onChange={(shops) =>
                            setData((current) => ({
                                ...current,
                                shops,
                                multi_branch: shops.length > 1 || current.multi_branch,
                                max_branches: Math.max(current.max_branches, shops.length),
                            }))
                        }
                        errors={errors}
                        nations={options.nations}
                        maxTills={options.maxTills}
                        maxShops={options.maxShops}
                    />
                </FormSection>

                <FormSection
                    title="Plan and licences"
                    description="The plan the tills are licensed on. Its features apply unless you customise them."
                >
                    <Field
                        id="plan_id"
                        label="Plan"
                        hint={options.plans.find((plan) => plan.value === data.plan_id)?.description}
                        error={errors.plan_id}
                    >
                        <Select value={data.plan_id} onValueChange={changePlan}>
                            <SelectTrigger id="plan_id" aria-invalid={!!errors.plan_id}>
                                <SelectValue placeholder="Choose a plan" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.plans.map((plan) => (
                                    <SelectItem key={plan.value} value={plan.value}>
                                        {plan.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <div className="sm:col-span-2">
                        <ApproveLicenceSection
                            shops={data.shops.map((shop) => ({
                                name: shop.name,
                                code: shop.code,
                                tills: shop.tills,
                                nation: shop.nation,
                                tills_allowed: shop.tills_allowed,
                            }))}
                            onTillsAllowed={(index, value) =>
                                setData(
                                    'shops',
                                    data.shops.map((shop, i) => (i === index ? { ...shop, tills_allowed: value } : shop)),
                                )
                            }
                            values={data}
                            setValue={(key, value) => setData(key, value as never)}
                            errors={errors}
                            options={options.licenceOptions}
                            trialDays={options.planDefaults[data.plan_id]?.trialDays ?? 7}
                            planFeatures={options.planDefaults[data.plan_id]?.features ?? []}
                        />
                    </div>
                </FormSection>

                <FormSection
                    title="Owner login"
                    description="We email the owner a link to set their password (or hold it, per Settings → Email settings)."
                >
                    <Field id="owner_name" label="Owner’s name" hint="Also the till’s first user in the licence keys." error={errors.owner_name}>
                        <Input
                            id="owner_name"
                            value={data.owner_name}
                            onChange={(e) => setData('owner_name', e.target.value)}
                            aria-invalid={!!errors.owner_name}
                        />
                    </Field>
                    <Field id="owner_email" label="Owner’s email" hint="Also their username for the portal." error={errors.owner_email}>
                        <Input
                            id="owner_email"
                            type="email"
                            value={data.owner_email}
                            onChange={(e) => setData('owner_email', e.target.value.trim())}
                            aria-invalid={!!errors.owner_email}
                        />
                    </Field>
                </FormSection>

                <FormSection
                    title="Billing"
                    description={byHand('The agreed setup fee and Direct Debit.', 'The agreed setup fee; invoices paid by hand.')}
                >
                    <Field
                        id="setup_fee_per_till"
                        label="Setup fee per till"
                        optional
                        hint={
                            agreedTotal
                                ? `${agreedTotal} for ${tills} ${tills === 1 ? 'till' : 'tills'}, before ${taxName()}. Also charged for each till added later.`
                                : `Blank: the plan’s fee (${formatPence(toPence(planFee.fee) ?? 0)}${planFee.note ? `, ${planFee.note}` : ''}).`
                        }
                        error={errors.setup_fee_per_till}
                    >
                        <MoneyInput
                            id="setup_fee_per_till"
                            value={data.setup_fee_per_till}
                            onChange={(e) => setData('setup_fee_per_till', e.target.value)}
                            invalid={!!errors.setup_fee_per_till}
                        />
                    </Field>
                    <div className="flex items-start gap-3 self-center">
                        <Checkbox
                            id="vat_applies"
                            checked={data.vat_applies}
                            onCheckedChange={(checked) => setData('vat_applies', checked === true)}
                            className="mt-0.5"
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="vat_applies">Charge {taxName()}</Label>
                            <p className="text-muted-foreground text-sm">On the setup fee and every invoice.</p>
                        </div>
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <OnboardingBillingNote options={options.billing} />
                        <p className="text-muted-foreground text-sm">
                            Record the setup fee payment on the business’s Billing tab when it is paid; {partner} is told it was paid.
                        </p>
                    </div>
                </FormSection>
            </FormCard>

            <StickyFormBar
                message={`Approving creates ${data.name || businessName} with ${tills} ${tills === 1 ? 'till' : 'tills'} and tells ${partner}.`}
            >
                <Button type="button" variant="outline" onClick={() => setRejecting(true)} disabled={processing}>
                    Reject
                </Button>
                <Button type="submit" disabled={processing}>
                    Approve and set up
                </Button>
            </StickyFormBar>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={`Approve ${data.name || businessName}?`}
                description={`We create the business with ${data.shops.length === 1 ? 'one shop' : `${data.shops.length} shops`} and ${tills} ${tills === 1 ? 'till' : 'tills'} with their licence keys, email the owner (unless welcome emails are held) and tell ${partner} it is approved. This cannot be undone here.`}
                confirmLabel="Approve and set up"
                onConfirm={approve}
                processing={processing}
            />
            <ReasonDialog
                open={rejecting}
                onOpenChange={setRejecting}
                title={`Reject ${businessName}?`}
                description={`Nothing is created. ${partner} sees your reason, so write it for them.`}
                confirmLabel="Reject request"
                url={route('admin.partner-requests.reject', requestId)}
                placeholder="For example: this business already has an account with us."
            />
        </form>
    );
}

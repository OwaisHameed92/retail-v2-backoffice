import { PlanFeaturePicker } from '@/components/admin/plans/plan-feature-picker';
import { CheckboxRow, Field, FormSection, MoneyInput, NumberInput } from '@/components/admin/plans/plan-form-fields';
import { formatMoney, slugify, yearlySaving } from '@/components/admin/plans/plan-format';
import { type FeatureOption, type PlanRecord } from '@/components/admin/plans/types';
import { FormCard } from '@/components/shared/form-section';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Link } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';

export interface PlanFormData {
    name: string;
    code: string;
    description: string;
    pricing_mode: string;
    price_monthly: string;
    price_yearly: string;
    setup_fee: string;
    trial_days: string;
    trial_grace_days: string;
    grace_days: string;
    features: string[];
    is_active: boolean;
    is_public: boolean;
    sort_order: string;
    [key: string]: string | string[] | boolean;
}

export function planFormDefaults(
    plan?: PlanRecord,
    defaults?: { trialDays: number; trialGraceDays: number; graceDays: number; sortOrder: number },
): PlanFormData {
    return {
        name: plan?.name ?? '',
        code: plan?.code ?? '',
        description: plan?.description ?? '',
        pricing_mode: plan?.pricingMode ?? 'perTill',
        price_monthly: plan?.priceMonthly ?? '',
        price_yearly: plan?.priceYearly ?? '',
        setup_fee: plan?.setupFee ?? '0.00',
        trial_days: String(plan?.trialDays ?? defaults?.trialDays ?? 7),
        trial_grace_days: String(plan?.trialGraceDays ?? defaults?.trialGraceDays ?? 3),
        grace_days: String(plan?.graceDays ?? defaults?.graceDays ?? 7),
        features: plan?.features ?? [],
        is_active: plan?.isActive ?? true,
        is_public: plan?.isPublic ?? false,
        sort_order: String(plan?.sortOrder ?? defaults?.sortOrder ?? 0),
    };
}

interface PlanFormProps {
    data: PlanFormData;
    setData: <K extends keyof PlanFormData>(key: K, value: PlanFormData[K]) => void;
    errors: Partial<Record<string, string>>;
    processing: boolean;
    features: FeatureOption[];
    onSubmit: FormEventHandler;
    submitLabel: string;
    cancelHref: string;
    /** On create, the code follows the name until someone edits it. */
    autoCode?: boolean;
    /** From useForm: shows "unsaved changes" in the action bar. */
    isDirty?: boolean;
}

export function PlanForm({
    data,
    setData,
    errors,
    processing,
    features,
    onSubmit,
    submitLabel,
    cancelHref,
    autoCode = false,
    isDirty = false,
}: PlanFormProps) {
    const [codeTouched, setCodeTouched] = useState(!autoCode);
    const saving = yearlySaving(data.price_monthly, data.price_yearly);
    const unit = data.pricing_mode === 'perBranch' ? 'branch' : 'till';

    const changeName = (name: string) => {
        setData('name', name);
        if (!codeTouched) {
            setData('code', slugify(name));
        }
    };

    return (
        <form onSubmit={onSubmit} className="grid gap-6">
            <FormCard>
                <FormSection title="Plan details" description="How the plan appears to our team and, if public, on the pricing page.">
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <Field id="name" label="Name" error={errors.name} help="For example Standard or Pro.">
                            <Input
                                id="name"
                                required
                                maxLength={100}
                                autoComplete="off"
                                value={data.name}
                                onChange={(e) => changeName(e.target.value)}
                                aria-invalid={!!errors.name || undefined}
                            />
                        </Field>
                        <Field id="code" label="Code" error={errors.code} help="Lower-case letters, numbers and hyphens. Must be unique.">
                            <Input
                                id="code"
                                required
                                maxLength={50}
                                autoComplete="off"
                                pattern="^[a-z0-9]+(-[a-z0-9]+)*$"
                                className="font-mono"
                                value={data.code}
                                onChange={(e) => {
                                    setCodeTouched(true);
                                    setData('code', e.target.value.toLowerCase().replace(/\s+/g, '-'));
                                }}
                                aria-invalid={!!errors.code || undefined}
                            />
                        </Field>
                    </div>
                    <Field
                        id="description"
                        label="Description"
                        error={errors.description}
                        help="One or two sentences. Optional, up to 500 characters."
                    >
                        <Textarea
                            id="description"
                            rows={3}
                            maxLength={500}
                            aria-invalid={!!errors.description || undefined}
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                    </Field>
                </FormSection>

                <FormSection
                    title="Pricing"
                    description="Price per till or per branch and the one-off setup fee, in pounds (GBP). A business can have its own pricing on its Billing tab."
                >
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <Field
                            id="pricing_mode"
                            label="Charge"
                            error={errors.pricing_mode}
                            help={
                                unit === 'branch'
                                    ? 'Each active branch pays one price, whatever its number of tills.'
                                    : 'Each live till pays one price.'
                            }
                        >
                            <Select value={data.pricing_mode} onValueChange={(value) => setData('pricing_mode', value)}>
                                <SelectTrigger id="pricing_mode">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="perTill">Per till</SelectItem>
                                    <SelectItem value="perBranch">Per branch</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        <div className="hidden sm:block" aria-hidden />
                        <Field
                            id="price_monthly"
                            label={`Monthly price per ${unit}`}
                            error={errors.price_monthly}
                            help={`Charged each month for each ${unit}.`}
                        >
                            <MoneyInput
                                id="price_monthly"
                                required
                                placeholder="30.00"
                                value={data.price_monthly}
                                invalid={!!errors.price_monthly}
                                onChange={(e) => setData('price_monthly', e.target.value)}
                            />
                        </Field>
                        <Field
                            id="price_yearly"
                            label={`Yearly price per ${unit}`}
                            error={errors.price_yearly}
                            help={
                                saving && saving.percent !== null ? (
                                    Number(saving.saving) > 0 ? (
                                        <span className="tabular-nums">
                                            Saves {formatMoney(saving.saving)} ({saving.percent}%) a year against paying monthly.
                                        </span>
                                    ) : Number(saving.saving) < 0 ? (
                                        <span className="text-warning-foreground tabular-nums">
                                            Costs more than 12 monthly payments ({formatMoney(String(-Number(saving.saving)))} extra).
                                        </span>
                                    ) : (
                                        'Same as 12 monthly payments.'
                                    )
                                ) : (
                                    `Charged once a year for each ${unit}.`
                                )
                            }
                        >
                            <MoneyInput
                                id="price_yearly"
                                required
                                placeholder="300.00"
                                value={data.price_yearly}
                                invalid={!!errors.price_yearly}
                                onChange={(e) => setData('price_yearly', e.target.value)}
                            />
                        </Field>
                        <Field
                            id="setup_fee"
                            label="Setup fee"
                            error={errors.setup_fee}
                            help="Charged once per business, before VAT. 0 for none. Can be changed per customer on their Billing tab."
                        >
                            <MoneyInput
                                id="setup_fee"
                                required
                                placeholder="0.00"
                                value={data.setup_fee}
                                invalid={!!errors.setup_fee}
                                onChange={(e) => setData('setup_fee', e.target.value)}
                            />
                        </Field>
                    </div>
                </FormSection>

                <FormSection title="Trial and grace" description="How long a new customer can try the till, and how long we wait before locking it.">
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                        <Field
                            id="trial_days"
                            label="Free trial"
                            error={errors.trial_days}
                            help="Starts when the till is first activated. 0 for no trial."
                        >
                            <NumberInput
                                id="trial_days"
                                required
                                min={0}
                                max={90}
                                suffix="days"
                                value={data.trial_days}
                                invalid={!!errors.trial_days}
                                onChange={(e) => setData('trial_days', e.target.value)}
                            />
                        </Field>
                        <Field
                            id="trial_grace_days"
                            label="Trial grace"
                            error={errors.trial_grace_days}
                            help="Extra days after the trial ends, with a warning, before the till locks."
                        >
                            <NumberInput
                                id="trial_grace_days"
                                required
                                min={0}
                                max={30}
                                suffix="days"
                                value={data.trial_grace_days}
                                invalid={!!errors.trial_grace_days}
                                onChange={(e) => setData('trial_grace_days', e.target.value)}
                            />
                        </Field>
                        <Field
                            id="grace_days"
                            label="Payment grace"
                            error={errors.grace_days}
                            help="Days after a missed renewal before licences are suspended."
                        >
                            <NumberInput
                                id="grace_days"
                                required
                                min={0}
                                max={60}
                                suffix="days"
                                value={data.grace_days}
                                invalid={!!errors.grace_days}
                                onChange={(e) => setData('grace_days', e.target.value)}
                            />
                        </Field>
                    </div>
                </FormSection>

                <FormSection title="Features" description="Areas of the till and portal this plan switches on.">
                    <PlanFeaturePicker
                        options={features}
                        value={data.features}
                        onChange={(value) => setData('features', value)}
                        error={errors.features ?? Object.entries(errors).find(([key]) => key.startsWith('features.'))?.[1]}
                    />
                </FormSection>

                <FormSection title="Availability" description="Who can be given this plan, and where it is listed.">
                    <div className="grid gap-4">
                        <CheckboxRow
                            id="is_active"
                            checked={data.is_active}
                            onChange={(checked) => setData('is_active', checked)}
                            label="Available for new licences"
                            help="Turn off to stop offering the plan. Tills already on it keep working."
                            error={errors.is_active}
                        />
                        <CheckboxRow
                            id="is_public"
                            checked={data.is_public}
                            onChange={(checked) => setData('is_public', checked)}
                            label="Show on the pricing page"
                            help="Leave off for plans agreed with one customer. The public pricing page is coming later."
                            error={errors.is_public}
                        />
                    </div>
                    <Field id="sort_order" label="Sort order" error={errors.sort_order} help="Lower numbers are listed first." className="max-w-40">
                        <NumberInput
                            id="sort_order"
                            required
                            min={0}
                            max={9999}
                            value={data.sort_order}
                            invalid={!!errors.sort_order}
                            onChange={(e) => setData('sort_order', e.target.value)}
                        />
                    </Field>
                </FormSection>
            </FormCard>

            <StickyFormBar message={isDirty ? 'You have unsaved changes.' : 'Prices are in pounds, per till.'}>
                <Button variant="outline" asChild>
                    <Link href={cancelHref}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                    {submitLabel}
                </Button>
            </StickyFormBar>
        </form>
    );
}

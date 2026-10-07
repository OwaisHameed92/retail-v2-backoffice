import {
    emptyUpfront,
    OnboardingBillingNote,
    onboardingPlanFee,
    UpfrontPaymentFields,
    type OnboardingBillingOptions,
    type UpfrontPaymentValue,
} from '@/components/admin/billing/upfront-payment-fields';
import { LicenceFormFields, licencePayload, type LicenceFormValues } from '@/components/admin/licences/licence-form-fields';
import { type LicenceOptions, type PlanDefaults, type PlanOption } from '@/components/admin/licences/types';
import { BranchFields, type BranchFieldsData } from '@/components/admin/tenants/branch-fields';
import { CompanyFields, type CompanyFieldsData } from '@/components/admin/tenants/company-fields';
import { Field, FormSection, Textarea } from '@/components/admin/tenants/field';
import { TillCountPicker } from '@/components/admin/tenants/till-count-picker';
import { type Nation, type Option } from '@/components/admin/tenants/types';
import { FormCard } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AdminLayout from '@/layouts/admin-layout';
import { taxIdFor } from '@/lib/country';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { CircleAlert, LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';
import { byHand } from '@/lib/billing-collection';

interface CreateTenantProps {
    nations: Option<Nation>[];
    maxTills: number;
    /** Active plans for the new tills' licences (module 1.3). */
    plans: PlanOption[];
    defaultPlanId: string | null;
    /** Module 1.11: the licence form and each plan's defaults. */
    licenceOptions: LicenceOptions;
    planDefaults: PlanDefaults;
    /** Module 1.13: the upfront payment and the Direct Debit deadline. */
    billing: OnboardingBillingOptions;
}

type CreateTenantForm = CompanyFieldsData &
    LicenceFormValues &
    UpfrontPaymentValue & {
        multi_branch: boolean;
        max_branches: number;
        status: 'trial' | 'active';
        trial_ends_at: string;
        notes: string;
        branch_code: string;
        branch_name: string;
        branch_nation: Nation;
        branch_address: string;
        branch_phone: string;
        branch_vat_number: string;
        branch_area_m2: string;
        branch_is_drs_return_point: boolean;
        branch_licensed_hours_json: string;
        branch_town: string;
        branch_postcode: string;
        branch_receipt_footer: string;
        tills: number;
        plan_id: string;
        owner_name: string;
        owner_email: string;
    };

const statusOptions = [
    { value: 'trial', title: 'Free trial', body: '7 days from the first till activation.' },
    { value: 'active', title: 'Active customer', body: 'Already agreed a plan and paying.' },
] as const;

export default function CreateTenant({ nations, maxTills, plans, defaultPlanId, licenceOptions, planDefaults, billing }: CreateTenantProps) {
    const initialPlanId = defaultPlanId ?? plans[0]?.value ?? '';
    const { data, setData, post, processing, errors, transform } = useForm<CreateTenantForm>({
        name: '',
        legal_name: '',
        vat_number: '',
        company_number: '',
        ...(taxIdFor('strn') ? { strn: '' } : {}),
        email: '',
        phone: '',
        contact_name: '',
        address: '',
        business_type: '',
        town: '',
        postcode: '',
        receipt_footer: '',
        status: 'trial',
        trial_ends_at: '',
        notes: '',
        branch_code: '',
        branch_name: '',
        branch_nation: 'england',
        branch_address: '',
        branch_phone: '',
        branch_vat_number: '',
        branch_area_m2: '',
        branch_is_drs_return_point: false,
        branch_licensed_hours_json: '',
        branch_town: '',
        branch_postcode: '',
        branch_receipt_footer: '',
        tills: 1,
        plan_id: initialPlanId,
        owner_name: '',
        owner_email: '',
        ...emptyUpfront,
        max_registers: 1,
        kind: 'trial',
        length: '',
        length_unit: '',
        valid_from: '',
        features: planDefaults[initialPlanId]?.features ?? [],
        multi_branch: planDefaults[initialPlanId]?.multiBranch ?? false,
        max_branches: planDefaults[initialPlanId]?.multiBranch ? 2 : 1,
    });

    transform((values) => ({ ...values, ...licencePayload(values), max_branches: values.multi_branch ? values.max_branches : 1 }));

    // A plan brings its features and multi-branch default; tills allowed never below the tills added.
    const changePlan = (planId: string) => {
        setData((current) => ({
            ...current,
            plan_id: planId,
            features: planDefaults[planId]?.features ?? current.features,
            multi_branch: planDefaults[planId]?.multiBranch ?? current.multi_branch,
        }));
    };
    const changeTills = (tills: number) => setData((current) => ({ ...current, tills, max_registers: Math.max(tills, current.max_registers) }));

    const branch: BranchFieldsData = {
        code: data.branch_code,
        name: data.branch_name,
        nation: data.branch_nation,
        address: data.branch_address,
        phone: data.branch_phone,
        vat_number: data.branch_vat_number,
        area_m2: data.branch_area_m2,
        is_drs_return_point: data.branch_is_drs_return_point,
        licensed_hours_json: data.branch_licensed_hours_json,
        town: data.branch_town,
        postcode: data.branch_postcode,
        receipt_footer: data.branch_receipt_footer,
    };

    const setBranchField = <K extends keyof BranchFieldsData>(key: K, value: BranchFieldsData[K]) =>
        setData(`branch_${key}` as keyof CreateTenantForm, value as never);

    const errorCount = Object.keys(errors).length;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.tenants.store'), { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title="Add tenant" />

            <PageHeader
                title="Add tenant"
                description="Set up a customer: their business, first shop, tills and the owner’s login."
                back={{ href: route('admin.tenants.index'), label: 'Tenants' }}
            />

            {errorCount > 0 && (
                <Alert variant="destructive">
                    <CircleAlert className="size-4" />
                    <AlertDescription>
                        {errorCount === 1 ? 'One field needs attention.' : `${errorCount} fields need attention.`} Check the highlighted fields below.
                    </AlertDescription>
                </Alert>
            )}

            <form onSubmit={submit} noValidate>
                <FormCard>
                    <FormSection title="Business details" description="The customer company. These details are sent to the till.">
                        <CompanyFields
                            data={data}
                            setData={(key, value) => setData(key, value)}
                            errors={errors}
                            businessTypes={licenceOptions.businessTypes}
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
                        {data.status === 'trial' && (
                            <Field
                                id="trial_ends_at"
                                label="Trial end date"
                                optional
                                hint="Leave blank to start the 7-day trial on the first till activation."
                                error={errors.trial_ends_at}
                            >
                                <Input
                                    id="trial_ends_at"
                                    type="date"
                                    value={data.trial_ends_at}
                                    onChange={(e) => setData('trial_ends_at', e.target.value)}
                                />
                            </Field>
                        )}
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

                    <FormSection title="First branch" description="The shop the tills are in. You can add more branches later.">
                        <BranchFields prefix="branch_" data={branch} setField={setBranchField} errors={errors} nations={nations} />
                    </FormSection>

                    <FormSection
                        title="Tills and licences"
                        description="One licence per till, issued now. The keys go to the owner in the welcome email. The first till is the main till: it syncs with the portal."
                    >
                        <div className="sm:col-span-2">
                            <TillCountPicker value={data.tills} max={maxTills} onChange={changeTills} error={errors.tills} />
                        </div>
                        {plans.length > 0 ? (
                            <Field
                                id="plan_id"
                                label="Plan"
                                hint={plans.find((plan) => plan.value === data.plan_id)?.description ?? 'Also used for tills added later.'}
                                error={errors.plan_id}
                            >
                                <Select value={data.plan_id} onValueChange={changePlan}>
                                    <SelectTrigger id="plan_id" aria-invalid={!!errors.plan_id}>
                                        <SelectValue placeholder="Choose a plan" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {plans.map((plan) => (
                                            <SelectItem key={plan.value} value={plan.value}>
                                                {plan.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        ) : (
                            <Alert variant="warning" className="sm:col-span-2">
                                <CircleAlert className="size-4" />
                                <AlertDescription>
                                    {errors.plan_id ??
                                        'There is no active plan yet, so the tills would have no licence keys and could not be used. Create a plan first.'}
                                </AlertDescription>
                            </Alert>
                        )}
                    </FormSection>

                    <FormSection
                        title="Licence form"
                        description="What every till key carries: tills allowed, trial or full, length and features. Defaults come from the plan; you can change them later per branch."
                    >
                        <div className="sm:col-span-2">
                            <LicenceFormFields
                                values={data}
                                setValue={(key, value) => setData(key, value as never)}
                                errors={errors}
                                options={licenceOptions}
                                showStart={false}
                                tillsInUse={data.tills}
                                trialDays={planDefaults[data.plan_id]?.trialDays}
                            />
                        </div>
                        <div className="flex items-start gap-3 sm:col-span-2">
                            <Checkbox
                                id="multi_branch"
                                checked={data.multi_branch}
                                onCheckedChange={(checked) => setData('multi_branch', checked === true)}
                                className="mt-0.5"
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="multi_branch">Multi-branch</Label>
                                <p className="text-muted-foreground text-sm">The business may run more than one shop.</p>
                            </div>
                        </div>
                        {data.multi_branch && (
                            <Field id="max_branches" label="Branches allowed" error={errors.max_branches}>
                                <Input
                                    id="max_branches"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={licenceOptions.maxBranches}
                                    value={data.max_branches}
                                    onChange={(e) => setData('max_branches', Number.parseInt(e.target.value || '0', 10))}
                                    className="max-w-32 tabular-nums"
                                    aria-invalid={!!errors.max_branches}
                                />
                            </Field>
                        )}
                    </FormSection>

                    <FormSection title="Owner login" description="We email the owner a link to set their password.">
                        <Field id="owner_name" label="Owner’s name" hint="Also the till’s first user in the licence keys." error={errors.owner_name}>
                            <Input
                                id="owner_name"
                                required
                                autoComplete="off"
                                value={data.owner_name}
                                onChange={(e) => setData('owner_name', e.target.value)}
                                aria-invalid={!!errors.owner_name}
                            />
                        </Field>
                        <Field id="owner_email" label="Owner’s email" hint="Also their username for the portal." error={errors.owner_email}>
                            <Input
                                id="owner_email"
                                type="email"
                                required
                                autoComplete="off"
                                value={data.owner_email}
                                onChange={(e) => setData('owner_email', e.target.value.trim())}
                                aria-invalid={!!errors.owner_email}
                            />
                        </Field>
                        <p className="text-muted-foreground text-sm sm:col-span-2">
                            If this email already has a portal login (for another business), they are added as an owner and keep their password.
                        </p>
                    </FormSection>

                    <FormSection
                        title="Billing"
                        description={byHand('Direct Debit, and what the business paid today.', 'Invoices paid by hand, and what the business paid today.')}
                    >
                        <div className="grid gap-4 sm:col-span-2">
                            <OnboardingBillingNote options={billing} />
                            {billing.canRecord && (
                                <UpfrontPaymentFields
                                    value={data}
                                    onChange={(key, value) => setData(key, value as never)}
                                    errors={errors}
                                    options={billing}
                                    planFee={onboardingPlanFee(billing, data.plan_id, data.tills).fee}
                                    planFeeNote={onboardingPlanFee(billing, data.plan_id, data.tills).note}
                                />
                            )}
                        </div>
                    </FormSection>
                </FormCard>

                <StickyFormBar message={'The owner gets a welcome email with their licence keys.'} className="mt-6">
                    <Button variant="outline" asChild>
                        <Link href={route('admin.tenants.index')}>Cancel</Link>
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Create tenant
                    </Button>
                </StickyFormBar>
            </form>
        </AdminLayout>
    );
}

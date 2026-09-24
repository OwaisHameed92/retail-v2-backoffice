import { BranchFields, type BranchFieldsData } from '@/components/admin/tenants/branch-fields';
import { CompanyFields, type CompanyFieldsData } from '@/components/admin/tenants/company-fields';
import { Field, FormSection, Textarea } from '@/components/admin/tenants/field';
import { TillCountPicker } from '@/components/admin/tenants/till-count-picker';
import { type Nation, type Option } from '@/components/admin/tenants/types';
import { PageHeader } from '@/components/shared/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AdminLayout from '@/layouts/admin-layout';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { CircleAlert, LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface CreateTenantProps {
    nations: Option<Nation>[];
    maxTills: number;
}

type CreateTenantForm = CompanyFieldsData & {
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
    tills: number;
    owner_name: string;
    owner_email: string;
};

const statusOptions = [
    { value: 'trial', title: 'Free trial', body: '7 days from the first till activation.' },
    { value: 'active', title: 'Active customer', body: 'Already agreed a plan and paying.' },
] as const;

export default function CreateTenant({ nations, maxTills }: CreateTenantProps) {
    const { data, setData, post, processing, errors } = useForm<CreateTenantForm>({
        name: '',
        legal_name: '',
        vat_number: '',
        company_number: '',
        email: '',
        phone: '',
        contact_name: '',
        address: '',
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
        tills: 1,
        owner_name: '',
        owner_email: '',
    });

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

            <PageHeader title="Add tenant" description="Set up a customer: their business, first shop, tills and the owner’s login." />

            {errorCount > 0 && (
                <Alert variant="destructive">
                    <CircleAlert className="size-4" />
                    <AlertDescription>
                        {errorCount === 1 ? 'One field needs attention.' : `${errorCount} fields need attention.`} Check the highlighted fields below.
                    </AlertDescription>
                </Alert>
            )}

            <form onSubmit={submit} noValidate>
                <Card className="p-4 sm:p-6">
                    <div className="grid gap-8">
                        <FormSection title="Business details" description="The customer company. These details are sent to the till.">
                            <CompanyFields data={data} setData={(key, value) => setData(key, value)} errors={errors} />
                        </FormSection>

                        <FormSection title="Account" description="How the customer starts with us.">
                            <fieldset className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
                                <legend className="sr-only">Account status</legend>
                                {statusOptions.map((option) => (
                                    <label
                                        key={option.value}
                                        className={cn(
                                            'focus-within:ring-ring flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors focus-within:ring-2',
                                            data.status === option.value ? 'border-primary bg-accent' : 'hover:bg-muted/50',
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

                        <FormSection title="Tills" description="One licence per till. The first till is the main till: it syncs with the portal.">
                            <div className="sm:col-span-2">
                                <TillCountPicker
                                    value={data.tills}
                                    max={maxTills}
                                    onChange={(value) => setData('tills', value)}
                                    error={errors.tills}
                                />
                            </div>
                        </FormSection>

                        <FormSection title="Owner login" description="We email the owner a link to set their password.">
                            <Field id="owner_name" label="Owner’s name" error={errors.owner_name}>
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
                    </div>

                    <div className="mt-8 flex flex-col-reverse gap-2 border-t pt-6 sm:flex-row sm:justify-end">
                        <Button variant="outline" asChild>
                            <Link href={route('admin.tenants.index')}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            Create tenant
                        </Button>
                    </div>
                </Card>
            </form>
        </AdminLayout>
    );
}

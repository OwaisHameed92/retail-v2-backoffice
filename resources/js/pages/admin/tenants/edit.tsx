import { CompanyFields, type CompanyFieldsData } from '@/components/admin/tenants/company-fields';
import { Field, FormSection, Textarea } from '@/components/admin/tenants/field';
import { toDateInput } from '@/components/admin/tenants/format';
import { type Option, type Tenant } from '@/components/admin/tenants/types';
import { FormCard } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@/layouts/admin-layout';
import { taxIdFor } from '@/lib/country';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

type EditTenantForm = CompanyFieldsData & { notes: string; trial_ends_at: string; owner_name: string };

export default function EditTenant({ tenant, businessTypes }: { tenant: Tenant; businessTypes: Option[] }) {
    const { data, setData, put, processing, errors, isDirty } = useForm<EditTenantForm>({
        name: tenant.name,
        legal_name: tenant.legalName ?? '',
        vat_number: tenant.vatNumber ?? '',
        company_number: tenant.companyNumber ?? '',
        ...(taxIdFor('strn') ? { strn: tenant.strn ?? '' } : {}),
        email: tenant.email ?? '',
        phone: tenant.phone ?? '',
        contact_name: tenant.contactName ?? '',
        address: tenant.address ?? '',
        business_type: tenant.businessType ?? '',
        town: tenant.town ?? '',
        postcode: tenant.postcode ?? '',
        receipt_footer: tenant.receiptFooter ?? '',
        owner_name: tenant.ownerName ?? '',
        notes: tenant.notes ?? '',
        trial_ends_at: toDateInput(tenant.trialEndsAt),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.tenants.update', tenant.id), { preserveScroll: true });
    };

    return (
        <AdminLayout
            breadcrumbs={[
                { title: 'Customers' },
                { title: 'Tenants', href: route('admin.tenants.index') },
                { title: tenant.name, href: route('admin.tenants.show', tenant.id) },
                { title: 'Edit' },
            ]}
        >
            <Head title={`Edit ${tenant.name}`} />

            <PageHeader
                title="Edit business details"
                status={<StatusBadge status={tenant.status} />}
                description="Changes reach the till on its next sync."
                back={{ href: route('admin.tenants.show', tenant.id), label: tenant.name }}
            />

            <form onSubmit={submit} noValidate>
                <FormCard>
                    <FormSection title="Business details" description="Name, legal details and contact.">
                        <CompanyFields data={data} setData={(key, value) => setData(key, value)} errors={errors} businessTypes={businessTypes} />
                        <Field
                            id="owner_name"
                            label="Owner’s name on the till"
                            optional
                            hint="The till’s first user. Licence keys carry it; never a PIN or password."
                            error={errors.owner_name}
                        >
                            <Input id="owner_name" maxLength={80} value={data.owner_name} onChange={(e) => setData('owner_name', e.target.value)} />
                        </Field>
                    </FormSection>

                    <FormSection title="Account" description="Status changes are on the tenant page.">
                        {tenant.status === 'trial' && (
                            <Field
                                id="trial_ends_at"
                                label="Trial end date"
                                optional
                                hint="Blank means the 7-day trial starts on the first till activation."
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
                            <Textarea id="notes" rows={4} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                        </Field>
                    </FormSection>
                </FormCard>

                <StickyFormBar message={isDirty ? 'You have unsaved changes.' : 'Changes reach the till on its next sync.'} className="mt-6">
                    <Button variant="outline" asChild>
                        <Link href={route('admin.tenants.show', tenant.id)}>Cancel</Link>
                    </Button>
                    <Button type="submit" disabled={processing || !isDirty}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Save changes
                    </Button>
                </StickyFormBar>
            </form>
        </AdminLayout>
    );
}

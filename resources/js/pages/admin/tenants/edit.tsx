import { CompanyFields, type CompanyFieldsData } from '@/components/admin/tenants/company-fields';
import { Field, FormSection, Textarea } from '@/components/admin/tenants/field';
import { toDateInput } from '@/components/admin/tenants/format';
import { type Tenant } from '@/components/admin/tenants/types';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

type EditTenantForm = CompanyFieldsData & { notes: string; trial_ends_at: string };

export default function EditTenant({ tenant }: { tenant: Tenant }) {
    const { data, setData, put, processing, errors, isDirty } = useForm<EditTenantForm>({
        name: tenant.name,
        legal_name: tenant.legalName ?? '',
        vat_number: tenant.vatNumber ?? '',
        company_number: tenant.companyNumber ?? '',
        email: tenant.email ?? '',
        phone: tenant.phone ?? '',
        contact_name: tenant.contactName ?? '',
        address: tenant.address ?? '',
        notes: tenant.notes ?? '',
        trial_ends_at: toDateInput(tenant.trialEndsAt),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.tenants.update', tenant.id), { preserveScroll: true });
    };

    return (
        <AdminLayout>
            <Head title={`Edit ${tenant.name}`} />

            <div>
                <Link
                    href={route('admin.tenants.show', tenant.id)}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
                >
                    <ArrowLeft className="size-4" />
                    {tenant.name}
                </Link>
            </div>

            <PageHeader
                title="Edit business details"
                description="Changes reach the till on its next sync."
                actions={<StatusBadge status={tenant.status} />}
            />

            <form onSubmit={submit} noValidate>
                <Card className="p-4 sm:p-6">
                    <div className="grid gap-8">
                        <FormSection title="Business details" description="Name, legal details and contact.">
                            <CompanyFields data={data} setData={(key, value) => setData(key, value)} errors={errors} />
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
                    </div>

                    <div className="mt-8 flex flex-col-reverse gap-2 border-t pt-6 sm:flex-row sm:justify-end">
                        <Button variant="outline" asChild>
                            <Link href={route('admin.tenants.show', tenant.id)}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={processing || !isDirty}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            Save changes
                        </Button>
                    </div>
                </Card>
            </form>
        </AdminLayout>
    );
}

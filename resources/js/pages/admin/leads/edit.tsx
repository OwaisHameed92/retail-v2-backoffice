import { LeadForm } from '@/components/admin/leads/lead-form';
import { type LeadFormData, type LeadOptions, type LeadRow } from '@/components/admin/leads/types';
import { PageHeader } from '@/components/shared/page-header';
import AdminLayout from '@/layouts/admin-layout';
import { Head } from '@inertiajs/react';

export default function EditLead({ lead, form, options }: { lead: LeadRow; form: LeadFormData; options: LeadOptions }) {
    return (
        <AdminLayout>
            <Head title={`Edit ${lead.businessName}`} />

            <PageHeader
                title="Edit lead"
                description={lead.businessName}
                breadcrumbs={[
                    { title: 'Leads', href: route('admin.leads.index') },
                    { title: lead.businessName, href: route('admin.leads.show', lead.id) },
                    { title: 'Edit' },
                ]}
            />

            <LeadForm
                mode="edit"
                options={options}
                initial={form}
                submitUrl={route('admin.leads.update', lead.id)}
                cancelHref={route('admin.leads.show', lead.id)}
                submitLabel="Save changes"
                message="Changes are recorded on the lead’s timeline."
            />
        </AdminLayout>
    );
}

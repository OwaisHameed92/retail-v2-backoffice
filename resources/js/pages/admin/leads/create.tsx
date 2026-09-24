import { LeadForm } from '@/components/admin/leads/lead-form';
import { type LeadOptions } from '@/components/admin/leads/types';
import { PageHeader } from '@/components/shared/page-header';
import AdminLayout from '@/layouts/admin-layout';
import { Head } from '@inertiajs/react';

export default function CreateLead({ options, defaultFollowUpTime }: { options: LeadOptions; defaultFollowUpTime: string }) {
    return (
        <AdminLayout>
            <Head title="Add lead" />

            <PageHeader
                title="Add lead"
                description="Someone who called, walked in or was referred. The team gets an email, and you can approve their trial from the lead page."
                back={{ href: route('admin.leads.index'), label: 'Leads' }}
            />

            <LeadForm
                mode="create"
                options={options}
                submitUrl={route('admin.leads.store')}
                cancelHref={route('admin.leads.index')}
                submitLabel="Add lead"
                message="We check for duplicates when you save."
                initial={{
                    business_name: '',
                    contact_name: '',
                    email: '',
                    phone: '',
                    town: '',
                    postcode: '',
                    shops_count: 1,
                    tills_count: 1,
                    business_type: 'convenience',
                    current_system: '',
                    message: '',
                    source: 'phone',
                    consent_marketing: false,
                    assigned_admin_id: '',
                    follow_up_date: '',
                    follow_up_time: defaultFollowUpTime,
                }}
            />
        </AdminLayout>
    );
}

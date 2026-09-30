import { CustomerForm } from '@/components/app/customers/customer-form';
import { PageHeader } from '@/components/shared/page-header';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function CreateCustomer() {
    return (
        <AppLayout>
            <Head title="Add customer" />
            <PageHeader
                title="Add customer"
                description="Every till gets the new customer at its next sync. Their balance and points start at zero and move with sales at the till."
                back={{ href: route('app.customers.index'), label: 'Customers' }}
            />
            <CustomerForm customer={null} canEdit />
        </AppLayout>
    );
}

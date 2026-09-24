import { AdminForm, type AdminFormData } from '@/components/admin/admin-form';
import { PageHeader } from '@/components/shared/page-header';
import { type RoleOption } from '@/components/admin/types';
import AdminLayout from '@/layouts/admin-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function CreateAdmin({ roles }: { roles: RoleOption[] }) {
    const { data, setData, post, processing, errors, reset } = useForm<AdminFormData>({
        name: '',
        email: '',
        role: 'support',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.admins.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AdminLayout>
            <Head title="Add admin user" />
            <PageHeader title="Add admin user" description="Give a member of Switch & Save staff access to the admin area." />
            <AdminForm
                data={data}
                setData={setData}
                errors={errors}
                processing={processing}
                roles={roles}
                onSubmit={submit}
                submitLabel="Add admin user"
            />
        </AdminLayout>
    );
}

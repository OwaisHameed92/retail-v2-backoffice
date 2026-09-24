import { AdminForm, type AdminFormData } from '@/components/admin/admin-form';
import { PageHeader } from '@/components/shared/page-header';
import { type AdminRecord, type RoleOption } from '@/components/admin/types';
import AdminLayout from '@/layouts/admin-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface EditAdminProps {
    editAdmin: AdminRecord;
    roles: RoleOption[];
    isSelf: boolean;
}

export default function EditAdmin({ editAdmin, roles, isSelf }: EditAdminProps) {
    const { data, setData, put, processing, errors, reset } = useForm<AdminFormData>({
        name: editAdmin.name,
        email: editAdmin.email,
        role: editAdmin.role,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.admins.update', editAdmin.id), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AdminLayout>
            <Head title={`Edit ${editAdmin.name}`} />
            <PageHeader
                title={`Edit ${editAdmin.name}`}
                description={isSelf ? 'This is your own account.' : editAdmin.isActive ? undefined : 'This admin user is inactive and cannot log in.'}
            />
            <AdminForm
                data={data}
                setData={setData}
                errors={errors}
                processing={processing}
                roles={roles}
                onSubmit={submit}
                submitLabel="Save changes"
                passwordOptional
            />
        </AdminLayout>
    );
}

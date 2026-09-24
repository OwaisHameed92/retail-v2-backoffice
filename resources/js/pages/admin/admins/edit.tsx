import { AdminStatusBadge } from '@/components/admin/admin-badges';
import { AdminForm, type AdminFormData } from '@/components/admin/admin-form';
import { type AdminRecord, type RoleOption } from '@/components/admin/types';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
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
                status={<AdminStatusBadge isActive={editAdmin.isActive} />}
                description={
                    isSelf ? 'This is your own account.' : editAdmin.isActive ? editAdmin.email : 'This admin user is inactive and cannot log in.'
                }
                back={{ href: route('admin.admins.index'), label: 'Admin users' }}
                media={<InitialsAvatar name={editAdmin.name} size="lg" />}
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

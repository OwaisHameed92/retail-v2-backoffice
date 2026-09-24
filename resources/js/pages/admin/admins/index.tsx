import { AdminRoleBadge, AdminStatusBadge } from '@/components/admin/admin-badges';
import { AdminStatusDialog } from '@/components/admin/admin-status-dialog';
import { formatDateTime } from '@/components/admin/format';
import { PageHeader } from '@/components/shared/page-header';
import { type AdminRecord, type AdminSharedData } from '@/components/admin/types';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Plus } from 'lucide-react';

interface AdminIndexProps {
    admins: AdminRecord[];
    status?: string;
}

export default function AdminIndex({ admins, status }: AdminIndexProps) {
    const { admin: me, errors } = usePage<AdminSharedData & { errors: Record<string, string> }>().props;

    return (
        <AdminLayout>
            <Head title="Admin users" />

            <PageHeader
                title="Admin users"
                description="Switch & Save staff who can log in to the admin area."
                actions={
                    <Button asChild>
                        <Link href={route('admin.admins.create')}>
                            <Plus />
                            Add admin user
                        </Link>
                    </Button>
                }
            />

            {status && (
                <Alert>
                    <CircleCheck className="size-4 text-green-600!" />
                    <AlertDescription>{status}</AlertDescription>
                </Alert>
            )}
            {errors.admin && (
                <Alert variant="destructive">
                    <CircleAlert className="size-4" />
                    <AlertDescription>{errors.admin}</AlertDescription>
                </Alert>
            )}

            <div className="overflow-x-auto rounded-xl border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-muted-foreground text-left">
                        <tr>
                            <th className="px-4 py-3 font-medium">Name</th>
                            <th className="hidden px-4 py-3 font-medium md:table-cell">Email</th>
                            <th className="px-4 py-3 font-medium">Role</th>
                            <th className="hidden px-4 py-3 font-medium sm:table-cell">Status</th>
                            <th className="hidden px-4 py-3 font-medium whitespace-nowrap lg:table-cell">Last login</th>
                            <th className="px-4 py-3">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {admins.map((row) => (
                            <tr key={row.id} className="hover:bg-muted/30">
                                <td className="px-4 py-3">
                                    <div className="font-medium">
                                        {row.name}
                                        {row.id === me.id && <span className="text-muted-foreground font-normal"> (you)</span>}
                                    </div>
                                    <div className="text-muted-foreground text-xs md:hidden">{row.email}</div>
                                    <div className="mt-1 sm:hidden">
                                        <AdminStatusBadge isActive={row.isActive} />
                                    </div>
                                </td>
                                <td className="text-muted-foreground hidden px-4 py-3 md:table-cell">{row.email}</td>
                                <td className="px-4 py-3">
                                    <AdminRoleBadge label={row.roleLabel} />
                                </td>
                                <td className="hidden px-4 py-3 sm:table-cell">
                                    <AdminStatusBadge isActive={row.isActive} />
                                </td>
                                <td className="text-muted-foreground hidden px-4 py-3 whitespace-nowrap lg:table-cell">
                                    {formatDateTime(row.lastLoginAt)}
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex justify-end gap-1">
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={route('admin.admins.edit', row.id)}>Edit</Link>
                                        </Button>
                                        {row.id !== me.id && <AdminStatusDialog admin={row} />}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AdminLayout>
    );
}

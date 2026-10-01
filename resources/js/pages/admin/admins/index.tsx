import { AdminRoleBadge, AdminStatusBadge } from '@/components/admin/admin-badges';
import { AdminStatusDialog } from '@/components/admin/admin-status-dialog';
import { formatDateTime } from '@/components/admin/format';
import { type AdminRecord, type AdminSharedData } from '@/components/admin/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EntityCell } from '@/components/shared/entity-cell';
import { MobileCardList } from '@/components/shared/mobile-card-list';
import { PageHeader } from '@/components/shared/page-header';
import { RowActions } from '@/components/shared/row-actions';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Pencil, Plus, ShieldOff, UserCheck, UserX } from 'lucide-react';
import { useState } from 'react';

interface AdminIndexProps {
    admins: AdminRecord[];
    status?: string;
}

export default function AdminIndex({ admins, status }: AdminIndexProps) {
    const { admin: me, errors } = usePage<AdminSharedData & { errors: Record<string, string> }>().props;
    const [statusFor, setStatusFor] = useState<AdminRecord | null>(null);
    const [resetFor, setResetFor] = useState<AdminRecord | null>(null);
    const withoutTwoFactor = admins.filter((row) => row.isActive && !row.twoFactorEnabled).length;
    const activeCount = admins.filter((row) => row.isActive).length;

    const actionsFor = (row: AdminRecord) => (
        <RowActions
            label={`Actions for ${row.name}`}
            actions={[
                { label: 'Edit', icon: Pencil, href: route('admin.admins.edit', row.id) },
                {
                    label: 'Reset two-factor',
                    icon: ShieldOff,
                    onSelect: () => setResetFor(row),
                    destructive: true,
                    hidden: row.id === me.id || !row.twoFactorEnabled,
                },
                { label: 'Reactivate', icon: UserCheck, onSelect: () => setStatusFor(row), hidden: row.id === me.id || row.isActive },
                { label: 'Deactivate', icon: UserX, onSelect: () => setStatusFor(row), destructive: true, hidden: row.id === me.id || !row.isActive },
            ]}
        />
    );

    const name = (row: AdminRecord) => (
        <EntityCell
            name={row.name}
            subline={row.email}
            suffix={row.id === me.id ? <span className="text-muted-foreground text-xs font-normal">(you)</span> : undefined}
        />
    );

    const open = (row: AdminRecord) => router.visit(route('admin.admins.edit', row.id));

    return (
        <AdminLayout>
            <Head title="Admin users" />

            <PageHeader
                title="Admin users"
                description={
                    <span className="tabular-nums">
                        Switch &amp; Save staff who can log in to the admin area. {activeCount} active of {admins.length}
                        {withoutTwoFactor > 0 ? `, ${withoutTwoFactor} without two-factor yet` : ''}.
                    </span>
                }
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
                <Alert variant="success">
                    <CircleCheck className="size-4" />
                    <AlertDescription>{status}</AlertDescription>
                </Alert>
            )}
            {errors.admin && (
                <Alert variant="destructive">
                    <CircleAlert className="size-4" />
                    <AlertDescription>{errors.admin}</AlertDescription>
                </Alert>
            )}

            <Card className="overflow-clip p-0">
                <div className="hidden md:block">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>Name</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Two-factor</TableHead>
                                <TableHead>Last login</TableHead>
                                <TableHead className="w-12">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {admins.map((row) => (
                                <TableRow
                                    key={row.id}
                                    tabIndex={0}
                                    onClick={() => open(row)}
                                    onKeyDown={(event) => event.key === 'Enter' && event.target === event.currentTarget && open(row)}
                                    className="focus-visible:bg-muted/60 cursor-pointer outline-none"
                                >
                                    <TableCell>{name(row)}</TableCell>
                                    <TableCell>
                                        <AdminRoleBadge label={row.roleLabel} />
                                    </TableCell>
                                    <TableCell>
                                        <AdminStatusBadge isActive={row.isActive} />
                                    </TableCell>
                                    <TableCell>
                                        <TwoFactorBadge enabled={row.twoFactorEnabled} />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground whitespace-nowrap tabular-nums">
                                        {formatDateTime(row.lastLoginAt)}
                                    </TableCell>
                                    <TableCell>{actionsFor(row)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
                <MobileCardList
                    className="md:hidden"
                    items={admins}
                    getKey={(row) => row.id}
                    onItemClick={open}
                    render={(row) => ({
                        title: name(row),
                        aside: <AdminStatusBadge isActive={row.isActive} />,
                        fields: [
                            { label: 'Role', value: row.roleLabel },
                            { label: 'Two-factor', value: <TwoFactorBadge enabled={row.twoFactorEnabled} /> },
                            { label: 'Last login', value: formatDateTime(row.lastLoginAt) },
                        ],
                        actions: actionsFor(row),
                    })}
                />
            </Card>

            <ConfirmDialog
                open={resetFor !== null}
                onOpenChange={(value) => !value && setResetFor(null)}
                title={`Reset two-factor sign-in for ${resetFor?.name ?? ''}?`}
                description="Their authenticator app, recovery codes and remembered devices stop working now. They must set up two-factor again at their next request. This is recorded in the audit log."
                confirmLabel="Reset two-factor"
                destructive
                onConfirm={() =>
                    new Promise<void>((resolve) =>
                        router.post(
                            route('admin.admins.two-factor.reset', resetFor?.id ?? ''),
                            {},
                            { preserveScroll: true, onFinish: () => resolve() },
                        ),
                    )
                }
            />

            {statusFor && <AdminStatusDialog admin={statusFor} open onOpenChange={(value) => !value && setStatusFor(null)} />}
        </AdminLayout>
    );
}

function TwoFactorBadge({ enabled }: { enabled: boolean }) {
    return <StatusBadge status={enabled ? 'on' : 'notSetUp'} tone={enabled ? 'success' : 'warning'} label={enabled ? 'On' : 'Not set up'} />;
}

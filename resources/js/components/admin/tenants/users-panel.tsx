import { formatDate } from '@/components/admin/tenants/format';
import { ImpersonateDialog } from '@/components/admin/tenants/impersonate-dialog';
import { type Option, type Tenant, type TenantMember } from '@/components/admin/tenants/types';
import { UserDialog } from '@/components/admin/tenants/user-dialog';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type CompanyRole } from '@/types';
import { router } from '@inertiajs/react';
import { LogIn, Mail, MoreHorizontal, Plus, UserCog, UserMinus, Users } from 'lucide-react';
import { useState } from 'react';

interface UsersPanelProps {
    tenant: Tenant;
    members: TenantMember[];
    roles: Option<CompanyRole>[];
    canManage: boolean;
    canImpersonate: boolean;
}

type Pending = { kind: 'remove' | 'link'; member: TenantMember } | null;

export function UsersPanel({ tenant, members, roles, canManage, canImpersonate }: UsersPanelProps) {
    const [dialog, setDialog] = useState<{ open: boolean; member: TenantMember | null }>({ open: false, member: null });
    const [pending, setPending] = useState<Pending>(null);
    const [impersonate, setImpersonate] = useState<number | null>(null);
    const activeOwners = members.filter((member) => member.isOwner && member.isActive).length;

    const send = (method: 'post' | 'delete', url: string) =>
        new Promise<void>((resolve) => {
            const options = { preserveScroll: true, onFinish: () => resolve() };
            if (method === 'delete') {
                router.delete(url, options);
            } else {
                router.post(url, {}, options);
            }
        });

    return (
        <Card className="overflow-hidden">
            <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                <div>
                    <h2 className="text-base font-semibold">Portal users</h2>
                    <p className="text-muted-foreground text-sm">People who can log in to the {tenant.name} portal.</p>
                </div>
                {canManage && (
                    <Button size="sm" onClick={() => setDialog({ open: true, member: null })}>
                        <Plus />
                        Add user
                    </Button>
                )}
            </div>

            {members.length === 0 ? (
                <div className="border-t">
                    <EmptyState icon={Users} title="No users yet" body="Add the owner so they can log in to the portal." />
                </div>
            ) : (
                <div className="border-t">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-4 sm:pl-5">Name</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead className="hidden md:table-cell">Added</TableHead>
                                <TableHead className="w-12 pr-4 sm:pr-5">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {members.map((member) => {
                                const lastOwner = member.isOwner && member.isActive && activeOwners <= 1;

                                return (
                                    <TableRow key={member.id}>
                                        <TableCell className="pl-4 sm:pl-5">
                                            <div className="font-medium">{member.name}</div>
                                            <div className="text-muted-foreground text-sm">{member.email}</div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge variant="outline" className="font-normal">
                                                    {member.roleLabel}
                                                </Badge>
                                                {!member.isActive && <StatusBadge status="inactive" />}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground hidden md:table-cell">{formatDate(member.joinedAt)}</TableCell>
                                        <TableCell className="pr-4 text-right sm:pr-5">
                                            {(canManage || canImpersonate) && (
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8"
                                                            aria-label={`Actions for ${member.name}`}
                                                        >
                                                            <MoreHorizontal />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end" className="w-56">
                                                        {canImpersonate && member.isActive && tenant.status !== 'cancelled' && (
                                                            <DropdownMenuItem onSelect={() => setImpersonate(member.id)}>
                                                                <LogIn />
                                                                Log in as {member.name.split(' ')[0]}
                                                            </DropdownMenuItem>
                                                        )}
                                                        {canManage && (
                                                            <>
                                                                <DropdownMenuItem
                                                                    disabled={lastOwner}
                                                                    onSelect={() => setDialog({ open: true, member })}
                                                                >
                                                                    <UserCog />
                                                                    {lastOwner ? 'Only owner: role locked' : 'Change role'}
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem onSelect={() => setPending({ kind: 'link', member })}>
                                                                    <Mail />
                                                                    Send set-password email
                                                                </DropdownMenuItem>
                                                                <DropdownMenuSeparator />
                                                                <DropdownMenuItem
                                                                    disabled={lastOwner}
                                                                    className="text-destructive focus:text-destructive [&_svg]:text-destructive"
                                                                    onSelect={() => setPending({ kind: 'remove', member })}
                                                                >
                                                                    <UserMinus />
                                                                    Remove access
                                                                </DropdownMenuItem>
                                                            </>
                                                        )}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            )}

            <UserDialog
                open={dialog.open}
                onOpenChange={(open) => setDialog((current) => ({ ...current, open }))}
                tenantId={tenant.id}
                tenantName={tenant.name}
                roles={roles}
                member={dialog.member}
            />

            {pending && (
                <ConfirmDialog
                    open
                    onOpenChange={(open) => !open && setPending(null)}
                    title={pending.kind === 'remove' ? `Remove ${pending.member.name}?` : `Email ${pending.member.name} a set-password link?`}
                    description={
                        pending.kind === 'remove'
                            ? `${pending.member.email} can no longer log in to the ${tenant.name} portal. Their account stays if they belong to another business.`
                            : `We send ${pending.member.email} a link to choose a new password. Their current password keeps working until they do.`
                    }
                    confirmLabel={pending.kind === 'remove' ? 'Remove access' : 'Send email'}
                    destructive={pending.kind === 'remove'}
                    onConfirm={() =>
                        pending.kind === 'remove'
                            ? send('delete', route('admin.tenants.users.destroy', [tenant.id, pending.member.id]))
                            : send('post', route('admin.tenants.users.password-link', [tenant.id, pending.member.id]))
                    }
                />
            )}

            {canImpersonate && (
                <ImpersonateDialog
                    open={impersonate !== null}
                    onOpenChange={(open) => !open && setImpersonate(null)}
                    tenantId={tenant.id}
                    tenantName={tenant.name}
                    members={members}
                    userId={impersonate}
                />
            )}
        </Card>
    );
}

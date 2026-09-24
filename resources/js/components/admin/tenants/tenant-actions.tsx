import { ImpersonateDialog } from '@/components/admin/tenants/impersonate-dialog';
import { ReasonDialog } from '@/components/admin/tenants/reason-dialog';
import { type Tenant, type TenantMember, type TenantStats } from '@/components/admin/tenants/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Link, router } from '@inertiajs/react';
import { Ban, CirclePause, CirclePlay, LogIn, MoreHorizontal, Pencil, RotateCcw } from 'lucide-react';
import { useState } from 'react';

interface TenantActionsProps {
    tenant: Tenant;
    stats: TenantStats;
    members: TenantMember[];
    canManage: boolean;
    canImpersonate: boolean;
}

type Dialog = 'suspend' | 'cancel' | 'activate' | 'unsuspend' | 'impersonate' | null;

const tills = (count: number) => (count === 1 ? '1 till' : `${count} tills`);

/** Header actions on the tenant page: Edit, Login as customer and the status changes. */
export function TenantActions({ tenant, stats, members, canManage, canImpersonate }: TenantActionsProps) {
    const [dialog, setDialog] = useState<Dialog>(null);
    const status = tenant.status;
    const post = (name: string) =>
        new Promise<void>((resolve) => router.post(route(name, tenant.id), {}, { preserveScroll: true, onFinish: () => resolve() }));

    if (!canManage && !canImpersonate) {
        return null;
    }

    const canActivate = ['trial', 'overdue', 'cancelled'].includes(status);
    const canSuspend = ['trial', 'active', 'overdue'].includes(status);

    return (
        <>
            {canManage && (
                <Button variant="outline" asChild>
                    <Link href={route('admin.tenants.edit', tenant.id)}>
                        <Pencil />
                        Edit
                    </Link>
                </Button>
            )}
            {canImpersonate && status !== 'cancelled' && (
                <Button variant="outline" onClick={() => setDialog('impersonate')}>
                    <LogIn />
                    Login as customer
                </Button>
            )}
            {canManage && status === 'suspended' && (
                <Button onClick={() => setDialog('unsuspend')}>
                    <CirclePlay />
                    Unsuspend
                </Button>
            )}
            {canManage && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" size="icon" aria-label="More actions">
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52">
                        {canActivate && (
                            <DropdownMenuItem onSelect={() => setDialog('activate')}>
                                {status === 'cancelled' ? <RotateCcw /> : <CirclePlay />}
                                {status === 'cancelled' ? 'Reinstate account' : 'Mark as active'}
                            </DropdownMenuItem>
                        )}
                        {canSuspend && (
                            <DropdownMenuItem onSelect={() => setDialog('suspend')}>
                                <CirclePause />
                                Suspend
                            </DropdownMenuItem>
                        )}
                        {status === 'suspended' && (
                            <DropdownMenuItem onSelect={() => setDialog('unsuspend')}>
                                <CirclePlay />
                                Unsuspend
                            </DropdownMenuItem>
                        )}
                        {status !== 'cancelled' && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    className="text-destructive focus:text-destructive [&_svg]:text-destructive"
                                    onSelect={() => setDialog('cancel')}
                                >
                                    <Ban />
                                    Cancel account
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}

            <ReasonDialog
                open={dialog === 'suspend'}
                onOpenChange={(open) => setDialog(open ? 'suspend' : null)}
                title={`Suspend ${tenant.name}?`}
                description={`Their users see an “account on hold” page instead of the portal, and their ${tills(stats.tills)} lock at the next check-in once licences arrive. You can unsuspend at any time.`}
                confirmLabel="Suspend"
                url={route('admin.tenants.suspend', tenant.id)}
                placeholder="For example: invoice INV-0042 unpaid for 30 days"
            />
            <ReasonDialog
                open={dialog === 'cancel'}
                onOpenChange={(open) => setDialog(open ? 'cancel' : null)}
                title={`Cancel ${tenant.name}?`}
                description="Their users are signed out and can no longer log in. Their data is kept, and you can reinstate the account later."
                confirmLabel="Cancel account"
                url={route('admin.tenants.cancel', tenant.id)}
                placeholder="For example: shop closed"
            />
            <ConfirmDialog
                open={dialog === 'activate'}
                onOpenChange={(open) => setDialog(open ? 'activate' : null)}
                title={status === 'cancelled' ? `Reinstate ${tenant.name}?` : `Mark ${tenant.name} as active?`}
                description={
                    status === 'cancelled'
                        ? 'Their users can log in again and the account becomes active.'
                        : 'Use this when the customer has agreed a plan. The trial ends.'
                }
                confirmLabel={status === 'cancelled' ? 'Reinstate' : 'Mark as active'}
                onConfirm={() => post('admin.tenants.activate')}
            />
            <ConfirmDialog
                open={dialog === 'unsuspend'}
                onOpenChange={(open) => setDialog(open ? 'unsuspend' : null)}
                title={`Unsuspend ${tenant.name}?`}
                description="Their users get the portal back straight away and the account returns to its previous status."
                confirmLabel="Unsuspend"
                onConfirm={() => post('admin.tenants.unsuspend')}
            />
            {canImpersonate && (
                <ImpersonateDialog
                    open={dialog === 'impersonate'}
                    onOpenChange={(open) => setDialog(open ? 'impersonate' : null)}
                    tenantId={tenant.id}
                    tenantName={tenant.name}
                    members={members}
                />
            )}
        </>
    );
}

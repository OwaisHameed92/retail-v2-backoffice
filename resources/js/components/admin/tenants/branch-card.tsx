import { RegisterDialog } from '@/components/admin/tenants/register-dialog';
import { type TenantBranch, type TenantRegister } from '@/components/admin/tenants/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { CirclePause, CirclePlay, MapPin, MonitorSmartphone, MoreHorizontal, Pencil, Phone, Plus, Recycle, Star } from 'lucide-react';
import { useState } from 'react';

interface BranchCardProps {
    tenantId: string;
    branch: TenantBranch;
    canManage: boolean;
    onEdit: (branch: TenantBranch) => void;
}

type Confirm = { kind: 'branch-deactivate' } | { kind: 'register-deactivate' | 'register-main'; register: TenantRegister } | null;

function postTo(url: string) {
    return new Promise<void>((resolve) => router.post(url, {}, { preserveScroll: true, onFinish: () => resolve() }));
}

/** One branch with its tills: details, till table, and the branch/till actions. */
export function BranchCard({ tenantId, branch, canManage, onEdit }: BranchCardProps) {
    const [tillDialog, setTillDialog] = useState<{ open: boolean; register: TenantRegister | null }>({ open: false, register: null });
    const [confirm, setConfirm] = useState<Confirm>(null);
    const activeTills = branch.registers.filter((register) => register.isActive).length;

    return (
        <Card className={cn('overflow-hidden', !branch.isActive && 'bg-muted/30')}>
            <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5">
                <div className="min-w-0 space-y-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-base font-semibold">{branch.name}</h3>
                        <Badge variant="outline" className="font-mono font-medium">
                            {branch.code}
                        </Badge>
                        {!branch.isActive && <StatusBadge status="inactive" />}
                    </div>
                    <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        <span className="inline-flex items-center gap-1.5">
                            <MapPin className="size-3.5" aria-hidden />
                            {branch.address ? branch.address.split('\n').join(', ') : 'No address'} · {branch.nationLabel}
                        </span>
                        {branch.phone && (
                            <span className="inline-flex items-center gap-1.5">
                                <Phone className="size-3.5" aria-hidden />
                                {branch.phone}
                            </span>
                        )}
                        {branch.isDrsReturnPoint && (
                            <span className="inline-flex items-center gap-1.5">
                                <Recycle className="size-3.5" aria-hidden />
                                Deposit return point
                            </span>
                        )}
                        {branch.areaM2 && <span className="tabular-nums">{branch.areaM2} m²</span>}
                    </div>
                </div>

                {canManage && (
                    <div className="flex shrink-0 items-center gap-2">
                        {branch.isActive && (
                            <Button variant="outline" size="sm" onClick={() => setTillDialog({ open: true, register: null })}>
                                <Plus />
                                Add till
                            </Button>
                        )}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" className="size-8" aria-label={`Actions for ${branch.name}`}>
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-48">
                                <DropdownMenuItem onSelect={() => onEdit(branch)}>
                                    <Pencil />
                                    Edit branch
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                {branch.isActive ? (
                                    <DropdownMenuItem
                                        className="text-destructive focus:text-destructive [&_svg]:text-destructive"
                                        onSelect={() => setConfirm({ kind: 'branch-deactivate' })}
                                    >
                                        <CirclePause />
                                        Deactivate branch
                                    </DropdownMenuItem>
                                ) : (
                                    <DropdownMenuItem onSelect={() => postTo(route('admin.tenants.branches.reactivate', [tenantId, branch.id]))}>
                                        <CirclePlay />
                                        Reactivate branch
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                )}
            </div>

            {branch.registers.length === 0 ? (
                <div className="border-t">
                    <EmptyState
                        icon={MonitorSmartphone}
                        title="No tills yet"
                        body="Add a till to issue its licence."
                        action={
                            canManage &&
                            branch.isActive && (
                                <Button size="sm" onClick={() => setTillDialog({ open: true, register: null })}>
                                    <Plus />
                                    Add till
                                </Button>
                            )
                        }
                    />
                </div>
            ) : (
                <div className="border-t">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="w-16 pl-4 sm:pl-5">Code</TableHead>
                                <TableHead>Till</TableHead>
                                <TableHead className="hidden sm:table-cell">Status</TableHead>
                                <TableHead className="w-12 pr-4 sm:pr-5">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {branch.registers.map((register) => (
                                <TableRow key={register.id}>
                                    <TableCell className="text-muted-foreground pl-4 font-mono sm:pl-5">{register.code}</TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className={cn('font-medium', !register.isActive && 'text-muted-foreground')}>{register.name}</span>
                                            {register.isMainTill && (
                                                <span className="bg-info-soft text-accent-foreground inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium">
                                                    <Star className="size-3" aria-hidden />
                                                    Main till
                                                </span>
                                            )}
                                            <span className="sm:hidden">
                                                <StatusBadge status={register.isActive ? 'active' : 'inactive'} />
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell className="hidden sm:table-cell">
                                        <StatusBadge status={register.isActive ? 'active' : 'inactive'} />
                                    </TableCell>
                                    <TableCell className="pr-4 text-right sm:pr-5">
                                        {canManage && (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        aria-label={`Actions for ${register.name}`}
                                                    >
                                                        <MoreHorizontal />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end" className="w-48">
                                                    <DropdownMenuItem onSelect={() => setTillDialog({ open: true, register })}>
                                                        <Pencil />
                                                        Edit till
                                                    </DropdownMenuItem>
                                                    {register.isActive && !register.isMainTill && (
                                                        <DropdownMenuItem onSelect={() => setConfirm({ kind: 'register-main', register })}>
                                                            <Star />
                                                            Make main till
                                                        </DropdownMenuItem>
                                                    )}
                                                    <DropdownMenuSeparator />
                                                    {register.isActive ? (
                                                        <DropdownMenuItem
                                                            className="text-destructive focus:text-destructive [&_svg]:text-destructive"
                                                            onSelect={() => setConfirm({ kind: 'register-deactivate', register })}
                                                        >
                                                            <CirclePause />
                                                            Deactivate till
                                                        </DropdownMenuItem>
                                                    ) : (
                                                        <DropdownMenuItem
                                                            disabled={!branch.isActive}
                                                            onSelect={() =>
                                                                postTo(route('admin.tenants.registers.reactivate', [tenantId, register.id]))
                                                            }
                                                        >
                                                            <CirclePlay />
                                                            Reactivate till
                                                        </DropdownMenuItem>
                                                    )}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <RegisterDialog
                open={tillDialog.open}
                onOpenChange={(open) => setTillDialog((current) => ({ ...current, open }))}
                tenantId={tenantId}
                branch={branch}
                register={tillDialog.register}
            />

            <ConfirmDialog
                open={confirm?.kind === 'branch-deactivate'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={`Deactivate ${branch.name}?`}
                description={`The branch and its ${activeTills === 1 ? 'till' : `${activeTills} tills`} stop being able to trade. Its data and receipts are kept, and you can reactivate it later.`}
                confirmLabel="Deactivate branch"
                destructive
                onConfirm={() => postTo(route('admin.tenants.branches.deactivate', [tenantId, branch.id]))}
            />
            {confirm && confirm.kind !== 'branch-deactivate' && (
                <ConfirmDialog
                    open
                    onOpenChange={(open) => !open && setConfirm(null)}
                    title={confirm.kind === 'register-main' ? `Make ${confirm.register.name} the main till?` : `Deactivate ${confirm.register.name}?`}
                    description={
                        confirm.kind === 'register-main'
                            ? `The main till syncs ${branch.name} with the portal. The current main till becomes a secondary till.`
                            : confirm.register.isMainTill
                              ? `It can no longer trade. It is the main till, so the next active till in ${branch.name} takes over syncing.`
                              : 'It can no longer trade. You can reactivate it later.'
                    }
                    confirmLabel={confirm.kind === 'register-main' ? 'Make main till' : 'Deactivate till'}
                    destructive={confirm.kind === 'register-deactivate'}
                    onConfirm={() =>
                        postTo(
                            route(confirm.kind === 'register-main' ? 'admin.tenants.registers.main' : 'admin.tenants.registers.deactivate', [
                                tenantId,
                                confirm.register.id,
                            ]),
                        )
                    }
                />
            )}
        </Card>
    );
}

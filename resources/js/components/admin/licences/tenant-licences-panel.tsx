import { ChangePlanDialog } from '@/components/admin/licences/change-plan-dialog';
import { licenceStatusLabels, plural } from '@/components/admin/licences/format';
import { requestKeys } from '@/components/admin/licences/issue-keys';
import { CheckIn, Device, EndDate, MaskedKey } from '@/components/admin/licences/licence-columns';
import { LicenceStatusBadge } from '@/components/admin/licences/licence-status-badge';
import { RenewDialog } from '@/components/admin/licences/renew-dialog';
import { type LicenceStatus, type PlanOption, type TenantLicensing } from '@/components/admin/licences/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { CalendarPlus, KeyRound, Layers, List, Plus, ShieldAlert } from 'lucide-react';
import { type KeyboardEvent, useState } from 'react';

interface TenantLicencesPanelProps {
    tenant: { id: string; name: string; status: string };
    licensing: TenantLicensing;
    plans: PlanOption[];
    canManage: boolean;
}

const ORDER: LicenceStatus[] = ['active', 'trial', 'grace', 'issued', 'expired', 'suspended', 'revoked'];

const RELOAD = ['licensing', 'branches', 'activity'];

/** The Licences tab of a tenant: every till's licence, plus issue missing, renew all and the plan for new tills. */
export function TenantLicencesPanel({ tenant, licensing, plans, canManage }: TenantLicencesPanelProps) {
    const [dialog, setDialog] = useState<'issue' | 'renew' | 'plan' | null>(null);
    const { summary, licences, plan } = licensing;
    const cancelled = tenant.status === 'cancelled';
    const chips = ORDER.filter((status) => summary.counts[status] > 0);

    const open = (row: string) => router.visit(route('admin.licences.show', row));
    const onKey = (event: KeyboardEvent<HTMLTableRowElement>, id: string) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            open(id);
        }
    };

    return (
        <>
            <Card className="overflow-hidden">
                <div className="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div className="min-w-0 space-y-2">
                        <h2 className="text-base font-semibold">Licences</h2>
                        <p className="text-muted-foreground text-sm">
                            One licence per till. {plural(summary.live, 'live licence')}
                            {summary.missing > 0 && (
                                <>
                                    , <span className="text-warning-foreground font-medium">{plural(summary.missing, 'active till')} without one</span>
                                </>
                            )}
                            .
                        </p>
                        {summary.openAlerts > 0 && (
                            <p className="text-warning-foreground flex items-center gap-1.5 text-sm font-medium">
                                <ShieldAlert className="size-4" aria-hidden />
                                {plural(summary.openAlerts, 'open licence alert')}: open a licence below to review it.
                            </p>
                        )}
                        {chips.length > 0 && (
                            <ul className="flex flex-wrap gap-1.5" aria-label="Licences by status">
                                {chips.map((status) => (
                                    <li key={status} className="bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-xs tabular-nums">
                                        {summary.counts[status]} {licenceStatusLabels[status].toLowerCase()}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="text-muted-foreground flex flex-wrap items-center gap-x-2 text-sm">
                            <Layers className="size-4" aria-hidden />
                            New tills get{' '}
                            <span className="text-foreground font-medium">{plan.current?.name ?? 'no plan (none is active)'}</span>
                            {plan.current && !plan.isCompanyPlan && <span>(the portal default)</span>}
                            {canManage && !cancelled && plans.length > 0 && (
                                <Button variant="link" size="sm" className="h-auto p-0" onClick={() => setDialog('plan')}>
                                    Change
                                </Button>
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 lg:shrink-0">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`${route('admin.licences.index')}?company=${tenant.id}`}>
                                <List />
                                Open in licence list
                            </Link>
                        </Button>
                        {canManage && !cancelled && summary.missing > 0 && (
                            <Button variant="outline" size="sm" onClick={() => setDialog('issue')}>
                                <Plus />
                                Issue missing licences
                            </Button>
                        )}
                        {canManage && summary.renewable > 0 && (
                            <Button size="sm" onClick={() => setDialog('renew')}>
                                <CalendarPlus />
                                Renew all
                            </Button>
                        )}
                    </div>
                </div>

                {licences.length === 0 ? (
                    <div className="border-t">
                        <EmptyState
                            icon={KeyRound}
                            title="No licences yet"
                            body={plan.current ? 'Issue a licence for each active till. You will see each key once.' : 'Create an active plan first, then issue the licences.'}
                            action={
                                canManage &&
                                !cancelled &&
                                summary.missing > 0 &&
                                plan.current && (
                                    <Button size="sm" onClick={() => setDialog('issue')}>
                                        <Plus />
                                        Issue missing licences
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
                                    <TableHead className="pl-4 sm:pl-5">Till</TableHead>
                                    <TableHead>Key</TableHead>
                                    <TableHead className="hidden sm:table-cell">Status</TableHead>
                                    <TableHead className="hidden md:table-cell">Expires</TableHead>
                                    <TableHead className="hidden lg:table-cell">PC</TableHead>
                                    <TableHead className="hidden xl:table-cell">Last check-in</TableHead>
                                    <TableHead className="hidden lg:table-cell pr-4 sm:pr-5">Plan</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {licences.map((row) => (
                                    <TableRow
                                        key={row.id}
                                        tabIndex={0}
                                        onClick={() => open(row.id)}
                                        onKeyDown={(event) => onKey(event, row.id)}
                                        className={cn('focus-visible:bg-muted/50 cursor-pointer focus-visible:outline-none', row.status === 'revoked' && 'text-muted-foreground')}
                                    >
                                        <TableCell className="pl-4 sm:pl-5">
                                            <div className="leading-tight">
                                                <div className="font-medium">
                                                    {row.register.name}
                                                    {row.register.code && <span className="text-muted-foreground font-mono text-xs font-normal"> · {row.register.code}</span>}
                                                </div>
                                                <div className="text-muted-foreground text-xs">{row.branch.name}</div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <MaskedKey value={row.maskedKey} />
                                            <div className="mt-1 sm:hidden">
                                                <LicenceStatusBadge status={row.status} reason={row.statusReason} />
                                            </div>
                                        </TableCell>
                                        <TableCell className="hidden sm:table-cell">
                                            <LicenceStatusBadge status={row.status} reason={row.statusReason} />
                                        </TableCell>
                                        <TableCell className="hidden md:table-cell">
                                            <EndDate row={row} />
                                        </TableCell>
                                        <TableCell className="hidden max-w-48 lg:table-cell">
                                            <Device row={row} />
                                        </TableCell>
                                        <TableCell className="hidden xl:table-cell">
                                            <CheckIn at={row.lastCheckInAt} />
                                        </TableCell>
                                        <TableCell className="hidden pr-4 lg:table-cell sm:pr-5">{row.plan?.name ?? '—'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </Card>

            <ConfirmDialog
                open={dialog === 'issue'}
                onOpenChange={(value) => setDialog(value ? 'issue' : null)}
                title={`Issue ${plural(summary.missing, 'licence')} for ${tenant.name}?`}
                description={`Each active till without a licence gets a new key on ${plan.current?.name ?? 'the default plan'}. You will see the keys once, to copy or email to the owner.`}
                confirmLabel="Issue licences"
                onConfirm={() => requestKeys(route('admin.tenants.licences.issue-missing', tenant.id), { only: RELOAD })}
            />
            <RenewDialog
                open={dialog === 'renew'}
                onOpenChange={(value) => setDialog(value ? 'renew' : null)}
                title={`Renew ${plural(summary.renewable, 'licence')} for ${tenant.name}?`}
                description="Every active till moves to the same new expiry date. Revoked licences and deactivated tills are left out."
                url={route('admin.tenants.licences.renew', tenant.id)}
                currentEnd={summary.latestEnd}
                confirmLabel="Renew all"
            />
            <ChangePlanDialog
                open={dialog === 'plan'}
                onOpenChange={(value) => setDialog(value ? 'plan' : null)}
                title="Change the plan for new tills?"
                description={`Tills added to ${tenant.name} from now on are licensed on this plan.`}
                url={route('admin.tenants.plan.update', tenant.id)}
                method="put"
                plans={plans}
                currentPlanId={plan.isCompanyPlan ? (plan.current?.id ?? null) : null}
                applyToLicences={summary.live}
            />
        </>
    );
}

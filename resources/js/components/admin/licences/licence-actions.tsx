import { ActivateByDialog } from '@/components/admin/licences/activate-by-dialog';
import { ChangePlanDialog } from '@/components/admin/licences/change-plan-dialog';
import { requestKeys } from '@/components/admin/licences/issue-keys';
import { RenewDialog } from '@/components/admin/licences/renew-dialog';
import { type LicenceDetail, type PlanOption } from '@/components/admin/licences/types';
import { ReasonDialog } from '@/components/admin/tenants/reason-dialog';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { router } from '@inertiajs/react';
import { Ban, CalendarClock, CalendarPlus, CirclePause, CirclePlay, KeyRound, Layers, Mail, MonitorX, MoreHorizontal } from 'lucide-react';
import { useState } from 'react';

type DialogName = 'renew' | 'plan' | 'release' | 'reissue' | 'resend' | 'activate-by' | 'suspend' | 'unsuspend' | 'revoke' | null;

function postTo(url: string) {
    return new Promise<void>((resolve) => router.post(url, {}, { preserveScroll: true, onFinish: () => resolve() }));
}

/** Header actions of the licence page, each confirmed with a dialog that names the consequence. */
export function LicenceActions({ licence, plans }: { licence: LicenceDetail; plans: PlanOption[] }) {
    const [dialog, setDialog] = useState<DialogName>(null);
    const till = `${licence.register.name} at ${licence.branch.name}`;
    const keyEnd = `…${licence.keyLast4}`;
    const open = (name: DialogName) => (value: boolean) => setDialog(value ? name : null);

    if (licence.isRevoked) {
        return null;
    }

    return (
        <>
            {licence.isSuspended ? (
                <Button onClick={() => setDialog('unsuspend')}>
                    <CirclePlay />
                    Unsuspend
                </Button>
            ) : (
                <Button onClick={() => setDialog('renew')}>
                    <CalendarPlus />
                    Renew
                </Button>
            )}
            <Button variant="outline" onClick={() => setDialog('reissue')}>
                <KeyRound />
                Reissue key
            </Button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline" size="icon" aria-label="More licence actions">
                        <MoreHorizontal />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    {licence.isSuspended && (
                        <DropdownMenuItem onSelect={() => setDialog('renew')}>
                            <CalendarPlus />
                            Renew
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onSelect={() => setDialog('resend')}>
                        <Mail />
                        Resend key e-mail
                    </DropdownMenuItem>
                    {licence.activateBy && (
                        <DropdownMenuItem onSelect={() => setDialog('activate-by')}>
                            <CalendarClock />
                            Extend activate-by date
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onSelect={() => setDialog('plan')}>
                        <Layers />
                        Change plan
                    </DropdownMenuItem>
                    <DropdownMenuItem disabled={!licence.isBound} onSelect={() => setDialog('release')}>
                        <MonitorX />
                        Release from PC
                    </DropdownMenuItem>
                    {!licence.isSuspended && (
                        <DropdownMenuItem onSelect={() => setDialog('suspend')}>
                            <CirclePause />
                            Suspend
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        className="text-destructive focus:text-destructive [&_svg]:text-destructive"
                        onSelect={() => setDialog('revoke')}
                    >
                        <Ban />
                        Revoke licence
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <RenewDialog
                open={dialog === 'renew'}
                onOpenChange={open('renew')}
                title={`Renew the licence for ${till}?`}
                description={
                    licence.isTrial || licence.status === 'issued'
                        ? 'The till becomes a paid licence. It picks up the new date at its next check-in.'
                        : 'The till picks up the new expiry date at its next check-in.'
                }
                url={route('admin.licences.renew', licence.id)}
                currentEnd={licence.endsAt}
            />
            <ChangePlanDialog
                open={dialog === 'plan'}
                onOpenChange={open('plan')}
                title={`Change the plan for ${till}?`}
                description="The plan’s features are copied onto this licence and the till gets them at its next check-in. Dates stay the same."
                url={route('admin.licences.plan', licence.id)}
                method="post"
                plans={plans}
                currentPlanId={licence.plan?.id ?? null}
            />
            <ConfirmDialog
                open={dialog === 'release'}
                onOpenChange={open('release')}
                title={`Release key ${keyEnd} from its PC?`}
                description={`${licence.deviceName ?? 'The current PC'} locks at its next check-in (“released”). The same key can then be activated on another PC.`}
                confirmLabel="Release"
                destructive
                onConfirm={() => postTo(route('admin.licences.release', licence.id))}
            />
            <ConfirmDialog
                open={dialog === 'reissue'}
                onOpenChange={open('reissue')}
                title={`Replace the key for ${till}?`}
                description={`Key ${keyEnd} stops working straight away and the licence is freed from its PC. You will see the new key once, to copy or email to the owner.`}
                confirmLabel="Replace key"
                destructive
                onConfirm={() => requestKeys(route('admin.licences.reissue', licence.id), { only: ['licence', 'timeline', 'activity'] })}
            />
            <ConfirmDialog
                open={dialog === 'resend'}
                onOpenChange={open('resend')}
                title={`Email a new key for ${till}?`}
                description={
                    licence.isBound
                        ? `Keys are not stored, so a new key is made and emailed to the owner. Key ${keyEnd} stops working and ${licence.deviceName ?? 'the current PC'} is released: it locks at its next check-in until the new key is entered.`
                        : `Keys are not stored, so a new key is made and emailed to the owner. Key ${keyEnd} stops working straight away.`
                }
                confirmLabel="Email new key"
                destructive={licence.isBound}
                onConfirm={() => postTo(route('admin.licences.resend', licence.id))}
            />
            <ActivateByDialog
                open={dialog === 'activate-by'}
                onOpenChange={open('activate-by')}
                licenceId={licence.id}
                keyLast4={licence.keyLast4}
                activateBy={licence.activateBy}
            />
            <ReasonDialog
                open={dialog === 'suspend'}
                onOpenChange={open('suspend')}
                title={`Suspend the licence for ${till}?`}
                description="The till stops trading at its next check-in. You can unsuspend it at any time."
                confirmLabel="Suspend licence"
                url={route('admin.licences.suspend', licence.id)}
                placeholder="For example: PC reported stolen"
            />
            <ConfirmDialog
                open={dialog === 'unsuspend'}
                onOpenChange={open('unsuspend')}
                title={`Unsuspend the licence for ${till}?`}
                description="The licence goes back to what its dates say. The till can trade again after its next check-in."
                confirmLabel="Unsuspend"
                onConfirm={() => postTo(route('admin.licences.unsuspend', licence.id))}
            />
            <ReasonDialog
                open={dialog === 'revoke'}
                onOpenChange={open('revoke')}
                title={`Revoke the licence for ${till}?`}
                description={`This cannot be undone. Key ${keyEnd} never works again and the till stops trading at its next check-in. You can then issue a new licence for the till.`}
                confirmLabel="Revoke for good"
                url={route('admin.licences.revoke', licence.id)}
                placeholder="For example: shop sold, till returned"
            />
        </>
    );
}

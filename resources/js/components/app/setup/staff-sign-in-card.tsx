import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { KeyRound, Nfc, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { FobDialog, PinDialog } from './staff-credential-dialogs';
import { type StaffMember } from './types';

/** Edit page, beside the form: PIN and fob (set, never shown) and removing the staff member. */
export function StaffSignInCard({ member, canEdit }: { member: StaffMember; canEdit: boolean }) {
    const [dialog, setDialog] = useState<'pin' | 'fob' | 'remove' | null>(null);
    const close = (open: boolean) => !open && setDialog(null);

    return (
        <div className="grid content-start gap-6">
            <SectionCard title="Signing in" description="PINs and fob codes are never shown, here or anywhere else.">
                <div className="grid gap-4">
                    <div className="flex items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <KeyRound className="text-muted-foreground size-4" aria-hidden />
                            <div>
                                <p className="text-sm font-medium">PIN</p>
                                <StatusPill tone={member.hasPin ? 'success' : 'warning'}>
                                    {member.hasPin ? 'Set' : member.pinNeedsReset ? 'Needs resetting' : 'Not set'}
                                </StatusPill>
                            </div>
                        </div>
                        {canEdit && (
                            <Button variant="outline" size="sm" onClick={() => setDialog('pin')}>
                                {member.hasPin ? 'Reset PIN' : 'Set PIN'}
                            </Button>
                        )}
                    </div>
                    <div className="flex items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <Nfc className="text-muted-foreground size-4" aria-hidden />
                            <div>
                                <p className="text-sm font-medium">Fob</p>
                                <StatusPill tone={member.hasFob ? 'info' : 'neutral'}>{member.hasFob ? 'Assigned' : 'None'}</StatusPill>
                            </div>
                        </div>
                        {canEdit && (
                            <Button variant="outline" size="sm" onClick={() => setDialog('fob')}>
                                {member.hasFob ? 'New fob' : 'Give fob'}
                            </Button>
                        )}
                    </div>
                    {member.pinNeedsReset && (
                        <p className="text-muted-foreground text-xs leading-5">
                            This PIN was saved in a format the tills cannot read, so it was never sent. Set a new PIN to sign in on the tills.
                        </p>
                    )}
                    {member.hasFob && (
                        <p className="text-muted-foreground text-xs leading-5">
                            To take a fob away, remove it on a till. If a fob is lost, give a new one or make them inactive: the old fob stops working
                            at the next sync.
                        </p>
                    )}
                </div>
            </SectionCard>

            {canEdit && (
                <SectionCard title="Remove from the tills" description="They can no longer sign in. Their past sales keep their name.">
                    <Button variant="outline" className="text-destructive w-full sm:w-auto" onClick={() => setDialog('remove')}>
                        <Trash2 />
                        Remove {member.name}
                    </Button>
                </SectionCard>
            )}

            {dialog === 'pin' && <PinDialog memberId={member.id} name={member.name} open onOpenChange={close} />}
            {dialog === 'fob' && <FobDialog memberId={member.id} name={member.name} hasFob={member.hasFob} open onOpenChange={close} />}
            <ConfirmDialog
                open={dialog === 'remove'}
                onOpenChange={close}
                title={`Remove ${member.name}?`}
                description="They are taken off every till at the next sync and can no longer sign in. To stop them for a while, make them inactive instead."
                confirmLabel="Remove staff member"
                destructive
                onConfirm={() =>
                    new Promise((resolve) =>
                        router.delete(route('app.staff.destroy', member.id), {
                            onFinish: () => {
                                setDialog(null);
                                resolve(null);
                            },
                        }),
                    )
                }
            />
        </div>
    );
}

import { type AdminSharedData } from '@/components/admin/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    BadgeCheck,
    CalendarClock,
    ChevronDown,
    CircleX,
    Pencil,
    PhoneCall,
    RotateCcw,
    UserRound,
    UserRoundCheck,
} from 'lucide-react';
import { useState } from 'react';
import { ApproveTrialDialog } from './approve-trial-dialog';
import { AssignDialog, ContactedDialog, FollowUpDialog, RejectDialog } from './lead-dialogs';
import { type LeadShowProps } from './types';

type DialogName = 'approve' | 'contacted' | 'followUp' | 'assign' | 'reject' | 'reopen' | 'archive' | null;

/**
 * Header actions on the lead page: "Approve 7-day trial" (primary), "Mark contacted", and a menu with assign,
 * follow-up, edit, reject, reopen and archive. Only what the lead's status and the admin's role allow is shown.
 */
export function LeadActions({
    lead,
    approval,
    options,
    defaultFollowUpTime,
    can,
}: Pick<LeadShowProps, 'lead' | 'approval' | 'options' | 'defaultFollowUpTime' | 'can'>) {
    const { admin } = usePage<AdminSharedData>().props;
    const [dialog, setDialog] = useState<DialogName>(null);
    const open = (name: DialogName) => () => setDialog(name);
    const close = (next: boolean) => !next && setDialog(null);

    if (!can.update) {
        return null;
    }

    const isOpen = (lead.status === 'new' || lead.status === 'contacted') && !lead.archived;
    const assignedToMe = lead.assignedAdmin?.id === admin.id;
    const canAssignMe = !assignedToMe && options.admins.some((option) => option.value === admin.id);

    if (lead.archived) {
        return (
            <Button onClick={() => router.post(route('admin.leads.restore', lead.id), {}, { preserveScroll: true })}>
                <ArchiveRestore />
                Restore lead
            </Button>
        );
    }

    return (
        <>
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline">
                        More
                        <ChevronDown />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="min-w-52">
                    {canAssignMe && (
                        <DropdownMenuItem
                            onSelect={() => router.post(route('admin.leads.assign', lead.id), { admin_id: admin.id }, { preserveScroll: true })}
                        >
                            <UserRoundCheck className="text-muted-foreground size-4" />
                            Assign to me
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onSelect={open('assign')}>
                        <UserRound className="text-muted-foreground size-4" />
                        {lead.assignedAdmin ? 'Change assignee' : 'Assign to…'}
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={open('followUp')}>
                        <CalendarClock className="text-muted-foreground size-4" />
                        {lead.followUpAt ? 'Change follow-up' : 'Set follow-up'}
                    </DropdownMenuItem>
                    {lead.status !== 'converted' && (
                        <DropdownMenuItem asChild>
                            <Link href={route('admin.leads.edit', lead.id)}>
                                <Pencil className="text-muted-foreground size-4" />
                                Edit details
                            </Link>
                        </DropdownMenuItem>
                    )}
                    {lead.status === 'rejected' && (
                        <DropdownMenuItem onSelect={open('reopen')}>
                            <RotateCcw className="text-muted-foreground size-4" />
                            Reopen lead
                        </DropdownMenuItem>
                    )}
                    {lead.status !== 'converted' && (
                        <>
                            <DropdownMenuSeparator />
                            {isOpen && (
                                <DropdownMenuItem onSelect={open('reject')} className="text-destructive focus:text-destructive">
                                    <CircleX className="text-destructive size-4" />
                                    Reject lead
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuItem onSelect={open('archive')} className="text-destructive focus:text-destructive">
                                <Archive className="text-destructive size-4" />
                                Archive lead
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            {isOpen && (
                <Button variant="outline" onClick={open('contacted')}>
                    <PhoneCall />
                    {lead.status === 'new' ? 'Mark contacted' : 'Log a call'}
                </Button>
            )}

            {isOpen && can.approve && approval && (
                <Button onClick={open('approve')}>
                    <BadgeCheck />
                    Approve {approval.trialDays}-day trial
                </Button>
            )}

            {approval && <ApproveTrialDialog lead={lead} approval={approval} open={dialog === 'approve'} onOpenChange={close} />}
            <ContactedDialog lead={lead} open={dialog === 'contacted'} onOpenChange={close} defaultTime={defaultFollowUpTime} />
            <FollowUpDialog lead={lead} open={dialog === 'followUp'} onOpenChange={close} defaultTime={defaultFollowUpTime} />
            <AssignDialog lead={lead} open={dialog === 'assign'} onOpenChange={close} options={options} />
            <RejectDialog lead={lead} open={dialog === 'reject'} onOpenChange={close} />

            <ConfirmDialog
                open={dialog === 'reopen'}
                onOpenChange={close}
                title={`Reopen ${lead.businessName}?`}
                description={`It goes back to ${lead.contactedAt ? 'Contacted' : 'New'} so you can work it again. The rejection stays on the timeline.`}
                confirmLabel="Reopen lead"
                onConfirm={() =>
                    new Promise((resolve) => router.post(route('admin.leads.reopen', lead.id), {}, { preserveScroll: true, onFinish: resolve }))
                }
            />
            <ConfirmDialog
                open={dialog === 'archive'}
                onOpenChange={close}
                title={`Archive ${lead.businessName}?`}
                description="It disappears from the lists and the board. Find it under the Archived filter to restore it."
                confirmLabel="Archive lead"
                destructive
                onConfirm={() => new Promise((resolve) => router.delete(route('admin.leads.archive', lead.id), { onFinish: resolve }))}
            />
        </>
    );
}

import { formatDate, type PortalInvitation } from '@/components/app/users/types';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { MobileCardList } from '@/components/shared/mobile-card-list';
import { RowActions, type RowAction } from '@/components/shared/row-actions';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { relativeTime } from '@/lib/relative-time';
import { MailPlus, Send, XCircle } from 'lucide-react';
import { type ReactNode } from 'react';

export type InvitationCommand = { kind: 'resend' | 'revoke'; invitation: PortalInvitation };

interface InvitationsPanelProps {
    invitations: PortalInvitation[];
    onCommand: (command: InvitationCommand) => void;
    inviteButton: ReactNode;
}

export function InvitationsPanel({ invitations, onCommand, inviteButton }: InvitationsPanelProps) {
    const actionsFor = (invitation: PortalInvitation): RowAction[] => [
        { label: 'Resend invitation', icon: Send, onSelect: () => onCommand({ kind: 'resend', invitation }) },
        { label: 'Cancel invitation', icon: XCircle, destructive: true, onSelect: () => onCommand({ kind: 'revoke', invitation }) },
    ];

    const statusCell = (invitation: PortalInvitation) => (
        <StatusBadge status={invitation.status} label={invitation.status === 'pending' ? 'Waiting' : 'Expired'} />
    );
    const accessCell = (invitation: PortalInvitation) => (
        <div className="flex flex-wrap items-center gap-1.5">
            <Badge variant={invitation.role === 'owner' ? 'info' : 'neutral'}>{invitation.roleLabel}</Badge>
            <span className="text-muted-foreground text-sm">{invitation.branchName ? `${invitation.branchName} only` : 'Every shop'}</span>
        </div>
    );
    const sentCell = (invitation: PortalInvitation) => (
        <span className="text-muted-foreground text-sm">
            {invitation.sentAt ? relativeTime(invitation.sentAt) : 'Not sent'}
            {invitation.invitedBy ? ` by ${invitation.invitedBy}` : ''}
            {invitation.sendCount > 1 ? ` · sent ${invitation.sendCount} times` : ''}
        </span>
    );
    const expiryCell = (invitation: PortalInvitation) => (
        <span className={invitation.status === 'expired' ? 'text-danger-foreground text-sm' : 'text-muted-foreground text-sm'}>
            {invitation.status === 'expired' ? `Expired ${formatDate(invitation.expiresAt)}` : `Until ${formatDate(invitation.expiresAt)}`}
        </span>
    );

    return (
        <SectionCard flush title="Invitations" description="Invitations not accepted yet. Resending sends a new link; the old one stops working.">
            {invitations.length === 0 ? (
                <EmptyState
                    icon={MailPlus}
                    size="sm"
                    title="No invitations waiting"
                    body="Invite a manager, your accountant or staff. They get an email with a link to join."
                    action={inviteButton}
                />
            ) : (
                <>
                    <div className="hidden md:block">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-5">Invited</TableHead>
                                    <TableHead>Access</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="hidden lg:table-cell">Sent</TableHead>
                                    <TableHead>Link</TableHead>
                                    <TableHead className="w-12 pr-5">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invitations.map((invitation) => (
                                    <TableRow key={invitation.id}>
                                        <TableCell className="pl-5">
                                            <EntityCell name={invitation.name} subline={invitation.email} />
                                        </TableCell>
                                        <TableCell>{accessCell(invitation)}</TableCell>
                                        <TableCell>{statusCell(invitation)}</TableCell>
                                        <TableCell className="hidden lg:table-cell">{sentCell(invitation)}</TableCell>
                                        <TableCell>{expiryCell(invitation)}</TableCell>
                                        <TableCell className="pr-5 text-right">
                                            <RowActions
                                                label={`Actions for the invitation to ${invitation.email}`}
                                                actions={actionsFor(invitation)}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <div className="p-4 md:hidden">
                        <MobileCardList
                            items={invitations}
                            getKey={(invitation) => invitation.id}
                            render={(invitation) => ({
                                title: <EntityCell name={invitation.name} subline={invitation.email} />,
                                aside: statusCell(invitation),
                                fields: [
                                    { label: 'Access', value: accessCell(invitation) },
                                    { label: 'Link', value: expiryCell(invitation) },
                                ],
                                actions: <RowActions label={`Actions for the invitation to ${invitation.email}`} actions={actionsFor(invitation)} />,
                            })}
                        />
                    </div>
                </>
            )}
        </SectionCard>
    );
}

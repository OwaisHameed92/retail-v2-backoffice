import { AccessDialog } from '@/components/app/users/access-dialog';
import { InvitationsPanel, type InvitationCommand } from '@/components/app/users/invitations-panel';
import { MembersPanel, type MemberCommand } from '@/components/app/users/members-panel';
import { RoleMatrix } from '@/components/app/users/role-matrix';
import { type PortalUsersProps } from '@/components/app/users/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Crown, MailPlus, Store, UserPlus, Users } from 'lucide-react';
import { useState } from 'react';

type Tab = 'users' | 'invitations' | 'roles';
type Pending = MemberCommand | InvitationCommand | null;

const send = (method: 'post' | 'delete', url: string) =>
    new Promise<void>((resolve) => {
        const options = { preserveScroll: true, onFinish: () => resolve() };
        if (method === 'delete') {
            router.delete(url, options);
        } else {
            router.post(url, {}, options);
        }
    });

export default function PortalUsers(props: PortalUsersProps) {
    const { members, invitations, stats, roles, matrix, branches, validDays } = props;
    const [tab, setTab] = useState<Tab>('users');
    const [dialog, setDialog] = useState<{ open: boolean; member: MemberCommand['member'] | null }>({ open: false, member: null });
    const [pending, setPending] = useState<Pending>(null);

    const invite = () => setDialog({ open: true, member: null });
    const inviteButton = (
        <Button onClick={invite}>
            <UserPlus />
            Invite user
        </Button>
    );

    const onMember = (command: MemberCommand) => (command.kind === 'edit' ? setDialog({ open: true, member: command.member }) : setPending(command));

    return (
        <AppLayout>
            <Head title="Portal users" />

            <PageHeader
                title="Portal users"
                description="Who can sign in to your business portal, what they can do and which shops they see."
                actions={inviteButton}
                tabs={
                    <PageTabs
                        label="Portal user sections"
                        value={tab}
                        onChange={(value) => setTab(value as Tab)}
                        tabs={[
                            { label: 'Users', value: 'users', count: members.length },
                            { label: 'Invitations', value: 'invitations', count: invitations.length },
                            { label: 'Roles', value: 'roles' },
                        ]}
                    />
                }
            />

            <div id={`tab-panel-${tab}`} role="tabpanel" className="grid gap-6">
                {tab === 'users' && (
                    <>
                        <StatGrid>
                            <StatCard
                                label="Active users"
                                value={stats.active}
                                hint={`${stats.deactivated} deactivated`}
                                icon={Users}
                                tone="primary"
                            />
                            <StatCard label="Owners" value={stats.owners} hint="A business always keeps one" icon={Crown} tone="neutral" />
                            <StatCard label="Shop managers" value={stats.oneShop} hint="Limited to one shop" icon={Store} tone="neutral" />
                            <StatCard
                                label="Invitations waiting"
                                value={stats.pendingInvitations}
                                hint={`Links last ${validDays} days`}
                                icon={MailPlus}
                                tone={stats.pendingInvitations > 0 ? 'warning' : 'neutral'}
                            />
                        </StatGrid>
                        <MembersPanel members={members} roles={roles} onCommand={onMember} />
                    </>
                )}
                {tab === 'invitations' && <InvitationsPanel invitations={invitations} onCommand={setPending} inviteButton={inviteButton} />}
                {tab === 'roles' && <RoleMatrix roles={roles} rows={matrix} />}
            </div>

            <AccessDialog
                open={dialog.open}
                onOpenChange={(open) => setDialog((current) => ({ ...current, open }))}
                roles={roles}
                branches={branches}
                validDays={validDays}
                member={dialog.member}
            />

            {pending && <PendingConfirm pending={pending} validDays={validDays} onClose={() => setPending(null)} />}
        </AppLayout>
    );
}

function PendingConfirm({ pending, validDays, onClose }: { pending: NonNullable<Pending>; validDays: number; onClose: () => void }) {
    const copy = (() => {
        switch (pending.kind) {
            case 'deactivate':
                return {
                    title: `Deactivate ${pending.member.name}?`,
                    description: `${pending.member.email} can no longer open this business’s portal. Their role and shop are kept, so you can reactivate them later.`,
                    confirm: 'Deactivate',
                    destructive: true,
                    run: () => send('post', route('app.users.deactivate', pending.member.id)),
                };
            case 'reactivate':
                return {
                    title: `Reactivate ${pending.member.name}?`,
                    description: `${pending.member.email} can sign in again as ${pending.member.roleLabel}${pending.member.branchName ? ` for ${pending.member.branchName}` : ''}.`,
                    confirm: 'Reactivate',
                    destructive: false,
                    run: () => send('post', route('app.users.reactivate', pending.member.id)),
                };
            case 'remove':
                return {
                    title: `Remove ${pending.member.name}?`,
                    description: `${pending.member.email} loses access to this business. Their account stays if they belong to another business. To add them again, send a new invitation.`,
                    confirm: 'Remove user',
                    destructive: true,
                    run: () => send('delete', route('app.users.destroy', pending.member.id)),
                };
            case 'resend':
                return {
                    title: `Resend the invitation to ${pending.invitation.email}?`,
                    description: `We email a new link that works for ${validDays} days. The link sent before stops working.`,
                    confirm: 'Resend',
                    destructive: false,
                    run: () => send('post', route('app.users.invitations.resend', pending.invitation.id)),
                };
            case 'revoke':
                return {
                    title: `Cancel the invitation to ${pending.invitation.email}?`,
                    description: 'The link in their email stops working. You can invite them again later.',
                    confirm: 'Cancel invitation',
                    cancel: 'Keep invitation',
                    destructive: true,
                    run: () => send('delete', route('app.users.invitations.destroy', pending.invitation.id)),
                };
            default:
                return null;
        }
    })();

    if (copy === null) {
        return null;
    }

    return (
        <ConfirmDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={copy.title}
            description={copy.description}
            confirmLabel={copy.confirm}
            cancelLabel={'cancel' in copy ? copy.cancel : undefined}
            destructive={copy.destructive}
            onConfirm={copy.run}
        />
    );
}

import { formatDate, type PortalMember } from '@/components/app/users/types';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { MobileCardList } from '@/components/shared/mobile-card-list';
import { RowActions, type RowAction } from '@/components/shared/row-actions';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type CompanyRole } from '@/types';
import { Search, UserCheck, UserCog, UserMinus, UserX, Users } from 'lucide-react';
import { useMemo, useState } from 'react';

export type MemberCommand = { kind: 'edit' | 'deactivate' | 'reactivate' | 'remove'; member: PortalMember };

interface MembersPanelProps {
    members: PortalMember[];
    roles: { value: CompanyRole; label: string }[];
    onCommand: (command: MemberCommand) => void;
}

const ALL = 'all';

export function MembersPanel({ members, roles, onCommand }: MembersPanelProps) {
    const [search, setSearch] = useState('');
    const [role, setRole] = useState<string>(ALL);
    const [status, setStatus] = useState<string>(ALL);
    const activeOwners = members.filter((member) => member.role === 'owner' && member.isActive).length;

    const rows = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return members.filter(
            (member) =>
                (needle === '' || member.name.toLowerCase().includes(needle) || member.email.toLowerCase().includes(needle)) &&
                (role === ALL || member.role === role) &&
                (status === ALL || (status === 'active') === member.isActive),
        );
    }, [members, search, role, status]);

    const actionsFor = (member: PortalMember): RowAction[] => {
        const lastOwner = member.role === 'owner' && member.isActive && activeOwners <= 1;
        const locked = member.isYou || lastOwner;
        const lockReason = member.isYou ? ' (not yourself)' : lastOwner ? ' (only owner)' : '';

        return [
            { label: `Change role or shop${lockReason}`, icon: UserCog, disabled: locked, onSelect: () => onCommand({ kind: 'edit', member }) },
            member.isActive
                ? { label: `Deactivate${lockReason}`, icon: UserX, disabled: locked, onSelect: () => onCommand({ kind: 'deactivate', member }) }
                : { label: 'Reactivate', icon: UserCheck, onSelect: () => onCommand({ kind: 'reactivate', member }) },
            {
                label: 'Remove from business',
                icon: UserMinus,
                destructive: true,
                disabled: locked,
                onSelect: () => onCommand({ kind: 'remove', member }),
            },
        ];
    };

    const roleCell = (member: PortalMember) => <Badge variant={member.role === 'owner' ? 'info' : 'neutral'}>{member.roleLabel}</Badge>;
    const shopCell = (member: PortalMember) =>
        member.branchName ? <span>{member.branchName} only</span> : <span className="text-muted-foreground">Every shop</span>;
    const statusCell = (member: PortalMember) => (
        <StatusBadge status={member.isActive ? 'active' : 'inactive'} label={member.isActive ? 'Active' : 'Deactivated'} />
    );
    const nameCell = (member: PortalMember) => (
        <EntityCell name={member.name} subline={member.email} suffix={member.isYou ? <Badge variant="neutral">You</Badge> : undefined} />
    );
    const filtered = search !== '' || role !== ALL || status !== ALL;

    return (
        <SectionCard
            flush
            title="Users"
            description="People who can sign in to this business’s portal."
            actions={
                <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <div className="relative sm:w-56">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" aria-hidden />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search name or email"
                            aria-label="Search users"
                            className="pl-8"
                        />
                    </div>
                    <Select value={role} onValueChange={setRole}>
                        <SelectTrigger className="sm:w-36" aria-label="Filter by role">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All roles</SelectItem>
                            {roles.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={status} onValueChange={setStatus}>
                        <SelectTrigger className="sm:w-40" aria-label="Filter by status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Any status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Deactivated</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            }
        >
            {rows.length === 0 ? (
                <EmptyState
                    icon={Users}
                    size="sm"
                    title={filtered ? 'No users match' : 'No users yet'}
                    body={filtered ? 'Try another name, role or status.' : 'Invite the people who run your shops.'}
                />
            ) : (
                <>
                    <div className="hidden md:block">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-5">Name</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead>Shops</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="hidden lg:table-cell">Added</TableHead>
                                    <TableHead className="w-12 pr-5">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((member) => (
                                    <TableRow key={member.id}>
                                        <TableCell className="pl-5">{nameCell(member)}</TableCell>
                                        <TableCell>{roleCell(member)}</TableCell>
                                        <TableCell>{shopCell(member)}</TableCell>
                                        <TableCell>{statusCell(member)}</TableCell>
                                        <TableCell className="text-muted-foreground hidden lg:table-cell">{formatDate(member.joinedAt)}</TableCell>
                                        <TableCell className="pr-5 text-right">
                                            <RowActions label={`Actions for ${member.name}`} actions={actionsFor(member)} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <div className="p-4 md:hidden">
                        <MobileCardList
                            items={rows}
                            getKey={(member) => String(member.id)}
                            render={(member) => ({
                                title: nameCell(member),
                                aside: statusCell(member),
                                fields: [
                                    { label: 'Role', value: roleCell(member) },
                                    { label: 'Shops', value: shopCell(member) },
                                ],
                                actions: <RowActions label={`Actions for ${member.name}`} actions={actionsFor(member)} />,
                            })}
                        />
                    </div>
                </>
            )}
        </SectionCard>
    );
}

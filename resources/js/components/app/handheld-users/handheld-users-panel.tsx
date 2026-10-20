import { lastSeen, type HandheldCommand, type HandheldShop, type HandheldUser } from '@/components/app/handheld-users/types';
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
import { KeyRound, LogOut, Pencil, Search, Smartphone, UserCheck, UserX } from 'lucide-react';
import { useMemo, useState } from 'react';

interface HandheldUsersPanelProps {
    users: HandheldUser[];
    shops: HandheldShop[];
    onCommand: (command: HandheldCommand) => void;
}

const ALL = 'all';

/** The handheld users list: search, shop and status filters, a table on desktop and cards on phones. */
export function HandheldUsersPanel({ users, shops, onCommand }: HandheldUsersPanelProps) {
    const [search, setSearch] = useState('');
    const [shop, setShop] = useState<string>(ALL);
    const [status, setStatus] = useState<string>(ALL);

    const shopOptions = useMemo(() => {
        const known = new Map(shops.map((option) => [option.id, option.name]));
        users.forEach((user) => !known.has(user.shopId) && known.set(user.shopId, user.shopName ?? 'Removed shop'));

        return [...known.entries()].map(([id, name]) => ({ id, name }));
    }, [shops, users]);

    const rows = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return users.filter(
            (user) =>
                (needle === '' || user.name.toLowerCase().includes(needle) || user.email.toLowerCase().includes(needle)) &&
                (shop === ALL || user.shopId === shop) &&
                (status === ALL || (status === 'active') === user.isActive),
        );
    }, [users, search, shop, status]);

    const actionsFor = (user: HandheldUser): RowAction[] => [
        { label: 'Edit details', icon: Pencil, onSelect: () => onCommand({ kind: 'edit', user }) },
        { label: 'Set a new password', icon: KeyRound, onSelect: () => onCommand({ kind: 'password', user }) },
        {
            label:
                user.devices > 0 ? `Sign out ${user.devices === 1 ? 'the device' : `${user.devices} devices`}` : 'Sign out devices (none signed in)',
            icon: LogOut,
            disabled: user.devices === 0,
            onSelect: () => onCommand({ kind: 'sign-out', user }),
        },
        user.isActive
            ? { label: 'Switch off', icon: UserX, destructive: true, onSelect: () => onCommand({ kind: 'deactivate', user }) }
            : { label: 'Switch on', icon: UserCheck, onSelect: () => onCommand({ kind: 'reactivate', user }) },
    ];

    const nameCell = (user: HandheldUser) => <EntityCell name={user.name} subline={user.email} />;
    const shopCell = (user: HandheldUser) => (
        <span className="inline-flex flex-wrap items-center gap-1.5">
            <span>{user.shopName ?? 'Removed shop'}</span>
            {!user.shopOpen && <Badge variant="warning">Closed</Badge>}
        </span>
    );
    const pricesCell = (user: HandheldUser) =>
        user.canChangePrices ? <Badge variant="info">Can change prices</Badge> : <span className="text-muted-foreground">Look up only</span>;
    const statusCell = (user: HandheldUser) => (
        <StatusBadge status={user.isActive ? 'active' : 'inactive'} label={user.isActive ? 'Active' : 'Switched off'} />
    );
    const devicesCell = (user: HandheldUser) => (
        <span className="text-sm">
            <span className="tabular-nums">{user.devices}</span>
            <span className="text-muted-foreground"> · {lastSeen(user)}</span>
        </span>
    );
    const filtered = search !== '' || shop !== ALL || status !== ALL;

    return (
        <SectionCard
            flush
            title="Handheld users"
            description="Each handheld user signs in to the PDA app and works in one shop."
            actions={
                <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <div className="relative sm:w-56">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" aria-hidden />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search name or email"
                            aria-label="Search handheld users"
                            className="pl-8"
                        />
                    </div>
                    <Select value={shop} onValueChange={setShop}>
                        <SelectTrigger className="sm:w-40" aria-label="Filter by shop">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Every shop</SelectItem>
                            {shopOptions.map((option) => (
                                <SelectItem key={option.id} value={option.id}>
                                    {option.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={status} onValueChange={setStatus}>
                        <SelectTrigger className="sm:w-36" aria-label="Filter by status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Any status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Switched off</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            }
        >
            {rows.length === 0 ? (
                <EmptyState icon={Smartphone} size="sm" title="No handheld users match" body="Try another name, shop or status." />
            ) : (
                <>
                    <div className="hidden md:block">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-5">Name</TableHead>
                                    <TableHead>Shop</TableHead>
                                    <TableHead>Prices</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="hidden lg:table-cell">Devices · last used</TableHead>
                                    <TableHead className="w-12 pr-5">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((user) => (
                                    <TableRow key={user.id}>
                                        <TableCell className="pl-5">{nameCell(user)}</TableCell>
                                        <TableCell>{shopCell(user)}</TableCell>
                                        <TableCell>{pricesCell(user)}</TableCell>
                                        <TableCell>{statusCell(user)}</TableCell>
                                        <TableCell className="hidden lg:table-cell">{devicesCell(user)}</TableCell>
                                        <TableCell className="pr-5 text-right">
                                            <RowActions label={`Actions for ${user.name}`} actions={actionsFor(user)} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <div className="p-4 md:hidden">
                        <MobileCardList
                            items={rows}
                            getKey={(user) => user.id}
                            render={(user) => ({
                                title: nameCell(user),
                                aside: statusCell(user),
                                fields: [
                                    { label: 'Shop', value: shopCell(user) },
                                    { label: 'Prices', value: pricesCell(user) },
                                    { label: 'Devices', value: devicesCell(user) },
                                ],
                                actions: <RowActions label={`Actions for ${user.name}`} actions={actionsFor(user)} />,
                            })}
                        />
                    </div>
                </>
            )}
            {filtered && rows.length > 0 && (
                <p className="text-muted-foreground border-t px-5 py-3 text-sm">
                    Showing {rows.length} of {users.length} handheld users
                </p>
            )}
        </SectionCard>
    );
}

import { HandheldUserDialog } from '@/components/app/handheld-users/handheld-user-dialog';
import { HandheldUsersPanel } from '@/components/app/handheld-users/handheld-users-panel';
import { PasswordDialog } from '@/components/app/handheld-users/password-dialog';
import { type HandheldCommand, type HandheldUser, type HandheldUsersProps } from '@/components/app/handheld-users/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { BadgePoundSterling, ScanBarcode, Smartphone, Truck, UserPlus, Users } from 'lucide-react';
import { useState } from 'react';

const post = (url: string) => new Promise<void>((resolve) => router.post(url, {}, { preserveScroll: true, onFinish: () => resolve() }));

export default function HandheldUsers({ users, shops, stats }: HandheldUsersProps) {
    const [editor, setEditor] = useState<{ open: boolean; user: HandheldUser | null }>({ open: false, user: null });
    const [passwordFor, setPasswordFor] = useState<HandheldUser | null>(null);
    const [pending, setPending] = useState<HandheldCommand | null>(null);
    const noShops = shops.length === 0;

    const add = () => setEditor({ open: true, user: null });
    const addButton = (
        <Button onClick={add} disabled={noShops} title={noShops ? 'Open a shop first' : undefined}>
            <UserPlus />
            Add handheld user
        </Button>
    );

    const onCommand = (command: HandheldCommand) => {
        if (command.kind === 'edit') {
            setEditor({ open: true, user: command.user });
        } else if (command.kind === 'password') {
            setPasswordFor(command.user);
        } else {
            setPending(command);
        }
    };

    return (
        <AppLayout>
            <Head title="Handheld (PDA) users" />

            <PageHeader
                title="Handheld (PDA) users"
                description="Sign-ins for the Android PDA app: scan products, check prices and stock, and order from suppliers for one shop."
                actions={addButton}
            />

            {users.length === 0 ? (
                <SectionCard>
                    <EmptyState
                        icon={Smartphone}
                        title="No handheld users yet"
                        body={
                            <div className="grid gap-3">
                                <p>
                                    The PDA app runs on Android handheld scanners. Staff sign in with an email and password you set here, and work in
                                    one shop:
                                </p>
                                <ul className="mx-auto grid max-w-md gap-2 text-left">
                                    <li className="flex gap-2">
                                        <ScanBarcode className="text-primary mt-0.5 size-4 shrink-0" aria-hidden />
                                        Scan a barcode to see the product, its shop price and stock.
                                    </li>
                                    <li className="flex gap-2">
                                        <Truck className="text-primary mt-0.5 size-4 shrink-0" aria-hidden />
                                        Build purchase orders for the shop; its till gets them at the next sync.
                                    </li>
                                    <li className="flex gap-2">
                                        <BadgePoundSterling className="text-primary mt-0.5 size-4 shrink-0" aria-hidden />
                                        Change selling prices, if you allow it for that user.
                                    </li>
                                </ul>
                                {noShops && <p>Open a shop under Shops and tills first.</p>}
                            </div>
                        }
                        action={addButton}
                    />
                </SectionCard>
            ) : (
                <div className="grid gap-6">
                    <StatGrid>
                        <StatCard label="Active" value={stats.active} hint={`${stats.inactive} switched off`} icon={Users} tone="primary" />
                        <StatCard
                            label="Signed-in devices"
                            value={stats.devices}
                            hint="Across every handheld user"
                            icon={Smartphone}
                            tone="neutral"
                        />
                        <StatCard
                            label="Can change prices"
                            value={stats.canChangePrices}
                            hint="Active users with the permission"
                            icon={BadgePoundSterling}
                            tone={stats.canChangePrices > 0 ? 'warning' : 'neutral'}
                        />
                        <StatCard label="Shops" value={shops.length} hint="Open shops to choose from" icon={Truck} tone="neutral" />
                    </StatGrid>
                    <HandheldUsersPanel users={users} shops={shops} onCommand={onCommand} />
                </div>
            )}

            <HandheldUserDialog
                open={editor.open}
                onOpenChange={(open) => setEditor((current) => ({ ...current, open }))}
                shops={shops}
                user={editor.user}
            />
            <PasswordDialog user={passwordFor} onOpenChange={(open) => !open && setPasswordFor(null)} />
            {pending && <PendingConfirm pending={pending} onClose={() => setPending(null)} />}
        </AppLayout>
    );
}

function PendingConfirm({ pending, onClose }: { pending: HandheldCommand; onClose: () => void }) {
    const { user } = pending;
    const devices = user.devices === 1 ? 'the device they are signed in on' : `the ${user.devices} devices they are signed in on`;
    const copy = {
        'sign-out': {
            title: `Sign ${user.name} out?`,
            description: `${user.email} is signed out of ${devices}. They can sign in again with their password.`,
            confirm: 'Sign out',
            destructive: true,
            url: route('app.handheld-users.sign-out', user.id),
        },
        deactivate: {
            title: `Switch ${user.name} off?`,
            description: `${user.email} is signed out at once and cannot sign in to the PDA app until you switch them on again.`,
            confirm: 'Switch off',
            destructive: true,
            url: route('app.handheld-users.deactivate', user.id),
        },
        reactivate: {
            title: `Switch ${user.name} on?`,
            description: `${user.email} can sign in to the PDA app again with their password, for ${user.shopName ?? 'their shop'}.`,
            confirm: 'Switch on',
            destructive: false,
            url: route('app.handheld-users.reactivate', user.id),
        },
    }[pending.kind as 'sign-out' | 'deactivate' | 'reactivate'];

    if (!copy) {
        return null;
    }

    return (
        <ConfirmDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={copy.title}
            description={copy.description}
            confirmLabel={copy.confirm}
            destructive={copy.destructive}
            onConfirm={() => post(copy.url)}
        />
    );
}

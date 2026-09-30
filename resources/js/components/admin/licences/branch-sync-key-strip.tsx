import { formatDateTimeShort, formatRelative } from '@/components/admin/licences/format';
import { type BranchSyncKey, type TenantBranch } from '@/components/admin/tenants/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { showToast } from '@/components/shared/toaster';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { sendJson } from '@/lib/http';
import { router } from '@inertiajs/react';
import { Check, CloudUpload, Copy, MoreHorizontal, RefreshCw, ShieldAlert, ShieldOff } from 'lucide-react';
import { useState } from 'react';

interface BranchSyncKeyStripProps {
    tenantId: string;
    branch: TenantBranch;
    canManage: boolean;
}

interface GeneratedKey {
    message: string;
    key: string;
    last4: string;
    branchName: string;
    businessName: string;
}

function statusBadge(syncKey: BranchSyncKey) {
    if (syncKey.status === 'active') {
        return <Badge variant="success">Active</Badge>;
    }
    if (syncKey.status === 'revoked') {
        return <Badge variant="danger">Revoked</Badge>;
    }

    return <Badge variant="neutral">No key</Badge>;
}

function describe(syncKey: BranchSyncKey): string {
    if (syncKey.status === 'revoked') {
        return `Revoked ${formatDateTimeShort(syncKey.revokedAt)}. The till does not sync until a new key is made.`;
    }
    if (syncKey.status === 'none') {
        return syncKey.cloudSync
            ? 'The main till gets a key with its licence the next time it checks in.'
            : 'Online dashboard is not in this branch’s licence. Generate a key only for a shop that connects later.';
    }
    const made = syncKey.source === 'till' ? `Sent to the main till ${formatDateTimeShort(syncKey.deliveredAt)}` : `Made by staff ${formatDateTimeShort(syncKey.createdAt)}`;

    return `${made} · Last used ${formatRelative(syncKey.lastUsedAt, 'never')}`;
}

/** One-time "Sync key created" dialog: the key lives only in this component's state until it closes. */
function SyncKeyRevealDialog({ generated, onClose }: { generated: GeneratedKey | null; onClose: () => void }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        if (!generated) {
            return;
        }
        try {
            await navigator.clipboard.writeText(generated.key);
            setCopied(true);
            showToast('Sync key copied.');
        } catch {
            showToast('Your browser blocked copying. Select the key and copy it instead.', 'error');
        }
    };

    return (
        <Dialog
            open={generated !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setCopied(false);
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-lg" onInteractOutside={(event) => event.preventDefault()}>
                <DialogHeader>
                    <div className="bg-info-soft text-primary mb-2 flex size-10 items-center justify-center rounded-full" aria-hidden>
                        <CloudUpload className="size-5" />
                    </div>
                    <DialogTitle>Sync key created</DialogTitle>
                    <DialogDescription>
                        On {generated?.branchName}’s main till: Settings → System → Network → Cloud sync → type the key → Connect.
                    </DialogDescription>
                </DialogHeader>
                <div className="bg-muted/50 grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-4">
                    <p
                        className="text-foreground font-mono text-base font-semibold tracking-wider break-all select-all"
                        aria-label={generated ? `Sync key ${generated.key.split('').join(' ')}` : undefined}
                    >
                        {generated?.key}
                    </p>
                    <Button type="button" variant={copied ? 'secondary' : 'outline'} size="sm" onClick={copy} className="justify-self-start">
                        {copied ? <Check className="text-success" /> : <Copy />}
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                </div>
                <div className="bg-warning-soft text-warning-foreground flex gap-3 rounded-lg p-3 text-sm">
                    <ShieldAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                    <p>
                        <span className="font-medium">You will not see this key again.</span> We keep only the last 4 characters ({generated?.last4}).
                        Any older key of this branch keeps working for 7 days.
                    </p>
                </div>
                <DialogFooter>
                    <Button type="button" onClick={onClose}>
                        Done
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** "Sync key: Active · SSK-…-7Q2D · last used 5 minutes ago" under a branch, with generate / rotate / revoke (module 2.1). */
export function BranchSyncKeyStrip({ tenantId, branch, canManage }: BranchSyncKeyStripProps) {
    const syncKey = branch.syncKey;
    const [generated, setGenerated] = useState<GeneratedKey | null>(null);
    const [confirm, setConfirm] = useState<'generate' | 'rotate' | 'revoke' | null>(null);
    const active = syncKey.status === 'active';

    const generate = async () => {
        const result = await sendJson<GeneratedKey>('POST', route('admin.tenants.branches.sync-key.generate', [tenantId, branch.id]));
        setConfirm(null);

        if (!result.ok || !result.data) {
            showToast(result.message ?? 'The sync key could not be created.', 'error');

            return;
        }
        setGenerated(result.data);
        router.reload({ only: ['branches', 'activity'] });
    };

    const visit = (method: 'post' | 'delete', name: string) =>
        new Promise<void>((resolve) => {
            router.visit(route(name, [tenantId, branch.id]), {
                method,
                preserveScroll: true,
                onFinish: () => {
                    setConfirm(null);
                    resolve();
                },
            });
        });

    return (
        <div className="flex flex-col gap-2 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div className="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1.5 text-[13px]">
                <span className="inline-flex items-center gap-1.5 font-medium">
                    <CloudUpload className="text-muted-foreground size-3.5" aria-hidden />
                    Sync key
                    {statusBadge(syncKey)}
                    {syncKey.rotationPending && <Badge variant="info">New key at next check</Badge>}
                </span>
                {syncKey.maskedKey && <span className="text-muted-foreground font-mono text-xs">{syncKey.maskedKey}</span>}
                <span className="text-muted-foreground">{describe(syncKey)}</span>
                {syncKey.oldKeysInGrace > 0 && (
                    <span className="text-muted-foreground">
                        {syncKey.oldKeysInGrace === 1 ? '1 older key' : `${syncKey.oldKeysInGrace} older keys`} still work for up to 7 days
                    </span>
                )}
            </div>
            {canManage && (
                <div className="flex items-center gap-1 self-start sm:self-auto">
                    <Button variant="outline" size="sm" onClick={() => (active ? setConfirm('generate') : void generate())}>
                        <RefreshCw />
                        {active ? 'Rotate key' : 'Generate key'}
                    </Button>
                    {active && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" className="size-8" aria-label={`More sync key actions for ${branch.name}`}>
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem onSelect={() => setConfirm('rotate')} disabled={syncKey.rotationPending}>
                                    <RefreshCw />
                                    Send a new key to the till
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    className="text-destructive focus:text-destructive [&_svg]:text-destructive"
                                    onSelect={() => setConfirm('revoke')}
                                >
                                    <ShieldOff />
                                    Revoke key
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            )}

            <ConfirmDialog
                open={confirm === 'generate'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={`Rotate ${branch.name}’s sync key?`}
                description="A new key is shown once, to type on the main till (Cloud sync → Connect). The current key keeps working for 7 days."
                confirmLabel="Rotate key"
                onConfirm={generate}
            />
            <ConfirmDialog
                open={confirm === 'rotate'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title="Send a new key to the till?"
                description="The main till gets a new sync key with its next licence check (usually within a day). Nobody sees it. The current key keeps working until 7 days after."
                confirmLabel="Send new key"
                onConfirm={() => visit('post', 'admin.tenants.branches.sync-key.rotate')}
            />
            <ConfirmDialog
                open={confirm === 'revoke'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={`Revoke ${branch.name}’s sync key?`}
                description="The till stops syncing at once, and no new key is sent to it until you generate one. Sales on the till carry on."
                confirmLabel="Revoke key"
                destructive
                onConfirm={() => visit('delete', 'admin.tenants.branches.sync-key.revoke')}
            />
            <SyncKeyRevealDialog generated={generated} onClose={() => setGenerated(null)} />
        </div>
    );
}

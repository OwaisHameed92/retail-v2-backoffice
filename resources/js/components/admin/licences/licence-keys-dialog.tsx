import { type AdminSharedData } from '@/components/admin/types';
import { onRevealLicenceKeys, type RevealRequest } from '@/components/admin/licences/reveal-keys';
import { type IssuedKey } from '@/components/admin/licences/types';
import { showToast } from '@/components/shared/toaster';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { sendJson } from '@/lib/http';
import { usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound, LoaderCircle, Mail, ShieldAlert } from 'lucide-react';
import { useEffect, useState } from 'react';

async function copyText(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch {
        return false;
    }
}

function KeyRow({ item, copied, onCopy }: { item: IssuedKey; copied: boolean; onCopy: () => void }) {
    return (
        <li className="bg-muted/50 grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-4">
            <div className="min-w-0">
                <p className="text-sm font-medium">
                    {item.tillName ?? 'Till'}
                    {item.tillCode && <span className="text-muted-foreground font-mono font-normal"> · {item.tillCode}</span>}
                </p>
                <p className="text-muted-foreground truncate text-xs">{[item.branchName, item.businessName].filter(Boolean).join(', ')}</p>
                <p
                    className="text-foreground mt-2 font-mono text-base font-semibold tracking-wider break-all select-all sm:text-lg"
                    aria-label={`Licence key ${item.key.split('').join(' ')}`}
                >
                    {item.key}
                </p>
            </div>
            <Button type="button" variant={copied ? 'secondary' : 'outline'} size="sm" onClick={onCopy} className="justify-self-start sm:justify-self-end">
                {copied ? <Check className="text-success" /> : <Copy />}
                {copied ? 'Copied' : 'Copy'}
            </Button>
        </li>
    );
}

/**
 * The one-time "Licence key created" dialog. Keys arrive through revealLicenceKeys() straight from the reply that
 * created them; they are held in this component's state only and dropped on close. Mounted once in AdminLayout.
 */
export function LicenceKeysDialog() {
    const { admin } = usePage<AdminSharedData>().props;
    const abilities = admin?.abilities ?? [];
    const canEmail = abilities.includes('licences.manage') || abilities.includes('tenants.manage');

    const [request, setRequest] = useState<RevealRequest | null>(null);
    const [copied, setCopied] = useState<Set<string>>(new Set());
    const [emailed, setEmailed] = useState(false);
    const [sending, setSending] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    useEffect(
        () =>
            onRevealLicenceKeys((next) => {
                setRequest(next);
                setCopied(new Set());
                setEmailed(false);
                setConfirmClose(false);
            }),
        [],
    );

    const keys = request?.keys ?? [];
    const single = keys.length === 1;
    const replaced = keys.some((item) => item.replacedKey);
    const saved = emailed || (keys.length > 0 && keys.every((item) => copied.has(item.licenceId)));

    const close = () => {
        setRequest(null);
        setConfirmClose(false);
    };

    const requestClose = (open: boolean) => {
        if (open || sending) {
            return;
        }
        if (!saved && !confirmClose) {
            setConfirmClose(true);

            return;
        }
        close();
    };

    const copy = async (items: IssuedKey[]) => {
        const text = items.map((item) => (items.length === 1 ? item.key : `${item.tillName ?? 'Till'} (${item.branchName ?? ''}): ${item.key}`)).join('\n');
        if (await copyText(text)) {
            setCopied((current) => new Set([...current, ...items.map((item) => item.licenceId)]));
            showToast(items.length === 1 ? 'Key copied.' : 'Keys copied.');
        } else {
            showToast('Your browser blocked copying. Select the key and copy it instead.', 'error');
        }
    };

    const email = async () => {
        setSending(true);
        const result = await sendJson<{ message: string }>('POST', route('admin.licences.email-keys'), {
            licences: keys.map((item) => ({ id: item.licenceId, key: item.key })),
        });
        setSending(false);

        if (result.ok && result.data) {
            setEmailed(true);
            setConfirmClose(false);
            showToast(result.data.message);
        } else {
            showToast(result.message ?? 'The email could not be sent.', 'error');
        }
    };

    const title = request?.title ?? (single ? (replaced ? 'New licence key created' : 'Licence key created') : `${keys.length} licence keys created`);

    return (
        <Dialog open={request !== null} onOpenChange={requestClose}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl" onInteractOutside={(event) => event.preventDefault()}>
                <DialogHeader>
                    <div className="bg-info-soft text-primary mb-2 flex size-10 items-center justify-center rounded-full" aria-hidden>
                        <KeyRound className="size-5" />
                    </div>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {replaced
                            ? 'The old key no longer works. Enter the new key on the till when it asks for it.'
                            : `Enter ${single ? 'this key' : 'each key'} on ${single ? 'the till' : 'its till'} the first time SSPOS opens there.`}
                    </DialogDescription>
                </DialogHeader>

                <div className="bg-warning-soft text-warning-foreground flex gap-3 rounded-lg p-3 text-sm">
                    <ShieldAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                    <p>
                        <span className="font-medium">You will not see {single ? 'this key' : 'these keys'} again.</span> We keep only the last 4
                        characters. Copy {single ? 'it' : 'them'} or email {single ? 'it' : 'them'} to the owner now.
                    </p>
                </div>

                <ul className="grid gap-2">
                    {keys.map((item) => (
                        <KeyRow key={item.licenceId} item={item} copied={copied.has(item.licenceId)} onCopy={() => copy([item])} />
                    ))}
                </ul>

                {confirmClose && (
                    <div role="alert" className="bg-danger-soft text-destructive grid gap-3 rounded-lg p-3 text-sm sm:flex sm:items-center sm:justify-between">
                        <p>Close without copying or emailing? The {single ? 'key' : 'keys'} cannot be shown again.</p>
                        <div className="flex shrink-0 gap-2">
                            <Button type="button" size="sm" variant="outline" onClick={() => setConfirmClose(false)}>
                                Go back
                            </Button>
                            <Button type="button" size="sm" variant="destructive" onClick={close}>
                                Close anyway
                            </Button>
                        </div>
                    </div>
                )}

                <DialogFooter className="gap-2 sm:justify-between">
                    <div className="flex flex-col gap-2 sm:flex-row">
                        {canEmail && (
                            <Button type="button" variant="outline" onClick={email} disabled={sending || emailed}>
                                {sending ? <LoaderCircle className="animate-spin" /> : emailed ? <Check className="text-success" /> : <Mail />}
                                {emailed ? 'Emailed to the owner' : single ? 'Email this key to the owner' : 'Email these keys to the owner'}
                            </Button>
                        )}
                        {!single && (
                            <Button type="button" variant="outline" onClick={() => copy(keys)}>
                                <Copy />
                                Copy all
                            </Button>
                        )}
                    </div>
                    <Button type="button" onClick={() => requestClose(false)}>
                        Done
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

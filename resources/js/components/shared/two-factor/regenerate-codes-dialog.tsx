import { FormField } from '@/components/shared/form-section';
import { showToast } from '@/components/shared/toaster';
import { RecoveryCodesPanel } from '@/components/shared/two-factor/recovery-codes-panel';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { sendJson } from '@/lib/http';
import { router } from '@inertiajs/react';
import { LoaderCircle, RefreshCw } from 'lucide-react';
import { useState, type FormEvent } from 'react';

/**
 * "New recovery codes": asks for the password, replaces all ten codes (the old ones stop working) and shows the new
 * ones once. `url` answers JSON `{ recoveryCodes }`.
 */
export function RegenerateCodesDialog({ url, only }: { url: string; only?: string[] }) {
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [codes, setCodes] = useState<string[] | null>(null);
    const [saved, setSaved] = useState(false);

    const close = (value: boolean) => {
        if (!value && codes && !saved && !window.confirm('Close without saving the new codes? The old codes no longer work.')) {
            return;
        }
        setOpen(value);
        if (!value) {
            if (codes) {
                router.reload({ only });
            }
            setPassword('');
            setError(null);
            setCodes(null);
            setSaved(false);
        }
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);
        const result = await sendJson<{ recoveryCodes: string[] }>('POST', url, { password });
        setBusy(false);
        setPassword('');

        if (result.ok && result.data) {
            setCodes(result.data.recoveryCodes);
            showToast('New recovery codes made.');
        } else {
            setError(result.errors.password ?? result.message);
        }
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <RefreshCw />
                    New recovery codes
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{codes ? 'Your new recovery codes' : 'Make new recovery codes?'}</DialogTitle>
                    <DialogDescription>
                        {codes ? 'Your old codes no longer work.' : 'All your current recovery codes stop working. Enter your password to go on.'}
                    </DialogDescription>
                </DialogHeader>

                {codes ? (
                    <>
                        <RecoveryCodesPanel codes={codes} onSavedChange={setSaved} />
                        <DialogFooter>
                            <Button onClick={() => close(false)} disabled={!saved}>
                                Done
                            </Button>
                        </DialogFooter>
                    </>
                ) : (
                    <form className="grid gap-4" onSubmit={submit}>
                        <FormField id="regenerate-password" label="Password" error={error ?? undefined}>
                            <Input
                                id="regenerate-password"
                                type="password"
                                autoComplete="current-password"
                                required
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                aria-invalid={!!error || undefined}
                            />
                        </FormField>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => close(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={busy || password === ''}>
                                {busy && <LoaderCircle className="size-4 animate-spin" />}
                                Make new codes
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

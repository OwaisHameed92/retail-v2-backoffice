import { type PartnerSettingsRow } from '@/components/admin/partners/types';
import { Field } from '@/components/admin/tenants/field';
import { showToast } from '@/components/shared/toaster';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { Check, Copy, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

/** Add a partner, or change its name, webhook URL and whether it is active. */
export function PartnerFormDialog({
    partner,
    open,
    onOpenChange,
}: {
    partner?: PartnerSettingsRow;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        name: partner?.name ?? '',
        webhook_url: partner?.webhookUrl ?? '',
        is_active: partner?.isActive ?? true,
    });

    const close = (next: boolean) => {
        if (processing) {
            return;
        }
        if (!next) {
            reset();
            clearErrors();
        }
        onOpenChange(next);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
        if (partner) {
            put(route('admin.settings.partners.update', partner.id), options);
        } else {
            post(route('admin.settings.partners.store'), { ...options, onSuccess: () => (reset(), onOpenChange(false)) });
        }
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>{partner ? `Edit ${partner.name}` : 'Add partner'}</DialogTitle>
                        <DialogDescription>A reseller platform that sends us signed EPOS deals through the partner API.</DialogDescription>
                    </DialogHeader>
                    <Field id="partner-name" label="Name" error={errors.name}>
                        <Input
                            id="partner-name"
                            autoFocus
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            aria-invalid={!!errors.name}
                        />
                    </Field>
                    <Field
                        id="partner-webhook"
                        label="Webhook URL"
                        optional
                        hint="Where we POST signed updates (approved, rejected, setup fee paid, first till, cancelled). https only."
                        error={errors.webhook_url}
                    >
                        <Input
                            id="partner-webhook"
                            type="url"
                            inputMode="url"
                            placeholder="https://"
                            value={data.webhook_url}
                            onChange={(e) => setData('webhook_url', e.target.value.trim())}
                            aria-invalid={!!errors.webhook_url}
                        />
                    </Field>
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="partner-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                            className="mt-0.5"
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="partner-active">Active</Label>
                            <p className="text-muted-foreground text-sm">Off: its keys are refused and no webhook is sent.</p>
                        </div>
                    </div>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => close(false)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            {partner ? 'Save changes' : 'Add partner'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** A key or secret that exists only now: copy it before closing (asks again if it was not copied). */
export function RevealSecretDialog({
    value,
    title,
    description,
    onClose,
}: {
    value: string | null;
    title: string;
    description: string;
    onClose: () => void;
}) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value ?? '');
            setCopied(true);
            showToast('Copied', 'success');
        } catch {
            showToast('Copy failed: select the text and copy it.', 'error');
        }
    };

    const close = () => {
        if (!copied && !window.confirm('You have not copied it. It cannot be shown again. Close anyway?')) {
            return;
        }
        setCopied(false);
        onClose();
    };

    return (
        <Dialog open={value !== null} onOpenChange={(open) => !open && close()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <div className="bg-muted flex items-center gap-2 rounded-lg border p-3">
                    <code className="min-w-0 flex-1 font-mono text-sm break-all select-all">{value}</code>
                    <Button type="button" size="sm" variant="outline" onClick={copy}>
                        {copied ? <Check /> : <Copy />}
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                </div>
                <DialogFooter>
                    <Button type="button" onClick={close}>
                        Done
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

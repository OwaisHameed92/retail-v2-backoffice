import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import { Check, LoaderCircle, RotateCcw, X } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';
import { DISMISS_REASONS } from './format';
import { type AnomalyRow } from './types';

/** Acknowledge, dismiss (asks for a reason) or reopen a finding. Owners and managers only (the server checks too). */
export function StatusActions({ anomaly, size = 'sm' }: { anomaly: Pick<AnomalyRow, 'id' | 'status' | 'title'>; size?: 'sm' | 'default' }) {
    const [dismissing, setDismissing] = useState(false);
    const [busy, setBusy] = useState(false);

    const change = (status: 'new' | 'acknowledged') =>
        router.put(
            route('app.anomalies.status', anomaly.id),
            { status },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );

    return (
        <div className="flex flex-wrap items-center gap-2">
            {anomaly.status === 'new' && (
                <Button size={size} variant="outline" disabled={busy} onClick={() => change('acknowledged')}>
                    {busy ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <Check className="size-4" aria-hidden />}
                    Acknowledge
                </Button>
            )}
            {anomaly.status !== 'dismissed' ? (
                <Button size={size} variant="outline" disabled={busy} onClick={() => setDismissing(true)}>
                    <X className="size-4" aria-hidden />
                    Dismiss
                </Button>
            ) : (
                <Button size={size} variant="outline" disabled={busy} onClick={() => change('new')}>
                    {busy ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <RotateCcw className="size-4" aria-hidden />}
                    Reopen
                </Button>
            )}
            {dismissing && <DismissDialog id={anomaly.id} title={anomaly.title} onClose={() => setDismissing(false)} />}
        </div>
    );
}

function DismissDialog({ id, title, onClose }: { id: string; title: string; onClose: () => void }) {
    const { data, setData, put, processing, errors } = useForm({ status: 'dismissed', reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('app.anomalies.status', id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Dismiss this finding?</DialogTitle>
                        <DialogDescription>
                            “{title}” leaves the open list and the same thing is not raised again for a while unless it gets worse. The reason is kept
                            in its history.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex flex-wrap gap-2">
                        {DISMISS_REASONS.map((reason) => (
                            <Button
                                key={reason}
                                type="button"
                                size="sm"
                                variant="secondary"
                                className="h-auto py-1 text-xs"
                                onClick={() => setData('reason', reason)}
                            >
                                {reason}
                            </Button>
                        ))}
                    </div>
                    <FormField id="anomaly-reason" label="Reason" error={errors.reason ?? errors.status}>
                        <Textarea
                            id="anomaly-reason"
                            rows={3}
                            maxLength={500}
                            required
                            value={data.reason}
                            aria-invalid={!!errors.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                        />
                    </FormField>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing || data.reason.trim() === ''}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                            Dismiss finding
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

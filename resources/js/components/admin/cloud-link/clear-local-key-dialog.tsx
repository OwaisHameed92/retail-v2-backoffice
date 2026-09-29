import { Field, Textarea } from '@/components/admin/tenants/field';
import { type LocalKeyRecord } from '@/components/admin/cloud-link/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface ClearLocalKeyDialogProps {
    record: LocalKeyRecord | null;
    onOpenChange: (open: boolean) => void;
}

/** Clears a local key register record (licences.manage): the next report of that key, from any PC, is a first sighting. */
export function ClearLocalKeyDialog({ record, onOpenChange }: ClearLocalKeyDialogProps) {
    const { data, setData, delete: destroy, processing, errors, reset, clearErrors } = useForm({ reason: '' });

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

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (record) {
            destroy(route('admin.cloud-link.local-keys.destroy', record.id), { preserveScroll: true, onSuccess: () => close(false) });
        }
    };

    return (
        <Dialog open={record !== null} onOpenChange={close}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Clear this local key record?</DialogTitle>
                        <DialogDescription>
                            Install code <span className="font-mono">{record?.installCode}</span> no longer owns this key. The next PC that reports it is
                            recorded as its first, and a PC that was refused may trade again once it reports. Use it when the dealer moved the key
                            to a new PC.
                        </DialogDescription>
                    </DialogHeader>
                    <Field id="clear-reason" label="Reason" hint="Kept in the activity log." error={errors.reason}>
                        <Textarea
                            id="clear-reason"
                            rows={3}
                            autoFocus
                            required
                            maxLength={200}
                            placeholder="e.g. Old PC replaced, dealer moved the key"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            aria-invalid={!!errors.reason}
                        />
                    </Field>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => close(false)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing || data.reason.trim().length < 3}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            Clear record
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

import { Field, Textarea } from '@/components/admin/tenants/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface ReasonDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    url: string;
    placeholder?: string;
}

/** Destructive status change that needs a reason (suspend, cancel). The reason goes to the activity log. */
export function ReasonDialog({ open, onOpenChange, title, description, confirmLabel, url, placeholder }: ReasonDialogProps) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ reason: '' });

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
        post(url, { preserveScroll: true, onSuccess: () => close(false) });
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>
                    <Field id="reason" label="Reason" hint="Kept in the activity log and shown to staff." error={errors.reason}>
                        <Textarea
                            id="reason"
                            rows={3}
                            autoFocus
                            required
                            maxLength={500}
                            placeholder={placeholder}
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
                            {confirmLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

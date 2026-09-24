import { Field } from '@/components/admin/tenants/field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface VoidInvoiceDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoice: { id: string; number: string | null; amountPaid: string; hasPayments: boolean; companyName: string };
}

/** Void an unpaid invoice (reason required), optionally with a corrected draft to re-issue. */
export function VoidInvoiceDialog(props: VoidInvoiceDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <VoidBody {...props} />}
        </Dialog>
    );
}

function VoidBody({ onOpenChange, invoice }: VoidInvoiceDialogProps) {
    const { data, setData, post, processing, errors } = useForm<{ reason: string; redraft: boolean }>({ reason: '', redraft: true });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('admin.billing.invoices.void', invoice.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>Void {invoice.number}?</DialogTitle>
                    <DialogDescription>
                        {invoice.companyName} will owe nothing on it. It keeps its number and stays in the records as void.
                        {invoice.hasPayments && ` The ${invoice.amountPaid} already paid on it goes back to their credit.`}
                    </DialogDescription>
                </DialogHeader>

                <Field id="void-reason" label="Reason" error={errors.reason} hint="Shown on the invoice and in the activity log.">
                    <Textarea id="void-reason" rows={3} maxLength={500} value={data.reason} onChange={(event) => setData('reason', event.target.value)} aria-invalid={!!errors.reason} autoFocus />
                </Field>

                <div className="flex items-start gap-3">
                    <Checkbox id="void-redraft" checked={data.redraft} onCheckedChange={(checked) => setData('redraft', checked === true)} className="mt-0.5" />
                    <div className="grid gap-1">
                        <Label htmlFor="void-redraft">Create a corrected draft</Label>
                        <p className="text-muted-foreground text-sm">A copy of the lines to fix and issue as a new invoice.</p>
                    </div>
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="destructive" disabled={processing || data.reason.trim() === ''}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Void invoice
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

export default VoidInvoiceDialog;

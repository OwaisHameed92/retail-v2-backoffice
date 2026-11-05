import { type CompanyRef, type UpfrontUndo } from '@/components/admin/billing/types';
import { Field } from '@/components/admin/tenants/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle, TriangleAlert } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface UndoUpfrontDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    company: CompanyRef;
    undo: UpfrontUndo;
}

/**
 * "Undo upfront payment (recorded by mistake)" (owner 2026-11-05): the payment is reversed and the setup fee invoice
 * voided, both kept in the records. A reason is required; no email goes to the customer.
 */
export function UndoUpfrontDialog(props: UndoUpfrontDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <UndoBody {...props} />}
        </Dialog>
    );
}

function UndoBody({ onOpenChange, company, undo }: UndoUpfrontDialogProps) {
    const { data, setData, post, processing, errors } = useForm<{ reason: string }>({ reason: '' });
    // A refusal from the server (the payment changed since the page loaded) comes back under "upfront".
    const refusal = (errors as Record<string, string | undefined>).upfront;
    const invoices = undo.invoices.join(', ');

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (processing) {
            return;
        }
        post(route('admin.billing.tenants.upfront.undo', company.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent className="sm:max-w-lg" onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>Undo the upfront payment of {company.name}?</DialogTitle>
                    <DialogDescription>
                        {undo.payment
                            ? `${undo.payment} (${undo.amount}) is reversed${invoices ? ` and ${invoices} ${undo.invoices.length === 1 ? 'is' : 'are'} voided` : ''}. Both stay in the records, marked with your reason.`
                            : `The setup fee was recorded as nothing to pay (${undo.amount}). The record is cleared so you can set the setup fee.`}{' '}
                        Tills, the Direct Debit and the monthly price do not change, and no email is sent.
                    </DialogDescription>
                </DialogHeader>

                {undo.payment && (
                    <Alert variant="warning">
                        <TriangleAlert className="size-4" aria-hidden />
                        <AlertDescription>
                            Use only when the money was not received. The payment is reversed and the setup fee invoice voided; you can then invoice
                            the setup fee again, e.g. in instalments.
                        </AlertDescription>
                    </Alert>
                )}

                <Field
                    id="undo-upfront-reason"
                    label="Reason"
                    error={errors.reason ?? refusal}
                    hint="Shown on the payment, the void invoice and in the activity log."
                >
                    <Textarea
                        id="undo-upfront-reason"
                        rows={3}
                        maxLength={500}
                        placeholder="e.g. Recorded as cash at onboarding; the customer pays in 3 monthly instalments"
                        value={data.reason}
                        onChange={(event) => setData('reason', event.target.value)}
                        aria-invalid={!!(errors.reason ?? refusal)}
                        aria-describedby={(errors.reason ?? refusal) ? 'undo-upfront-reason-error' : 'undo-upfront-reason-help'}
                        autoFocus
                    />
                </Field>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="destructive" disabled={processing || data.reason.trim() === ''}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Undo upfront payment
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

export default UndoUpfrontDialog;

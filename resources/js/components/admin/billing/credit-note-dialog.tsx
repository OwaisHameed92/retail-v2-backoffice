import { formatPence, toPence } from '@/components/admin/billing/money';
import { Field } from '@/components/admin/tenants/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { currencySymbol, wideCurrencySymbol } from '@/lib/country';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface CreditNoteDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoice: { id: string; number: string | null; balance: string; maxCredit: string; vatRate: string };
}

/** A simple credit note: an amount (VAT included) off what is still owed, with a reason. */
export function CreditNoteDialog(props: CreditNoteDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <CreditBody {...props} />}
        </Dialog>
    );
}

function CreditBody({ onOpenChange, invoice }: CreditNoteDialogProps) {
    const { data, setData, post, processing, errors } = useForm<{ amount: string; reason: string }>({ amount: '', reason: '' });
    const amount = toPence(data.amount);
    const max = toPence(invoice.maxCredit) ?? 0;
    const over = amount !== null && amount > max;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('admin.billing.invoices.credit', invoice.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>Add a credit note</DialogTitle>
                    <DialogDescription>
                        Takes an amount off {invoice.number}, for example goodwill for downtime. Up to {invoice.balance} is still owed. To cancel the
                        whole invoice, void it instead.
                    </DialogDescription>
                </DialogHeader>

                <Field
                    id="credit-amount"
                    label="Amount, VAT included"
                    error={errors.amount ?? (over ? `The most you can credit is ${formatPence(max)}.` : undefined)}
                    hint={invoice.vatRate !== '0.00' ? 'Split into net and VAT at the invoice’s rate.' : undefined}
                >
                    <div className="relative">
                        <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm">
                            {currencySymbol()}
                        </span>
                        <Input
                            id="credit-amount"
                            inputMode="decimal"
                            autoComplete="off"
                            value={data.amount}
                            onChange={(event) => setData('amount', event.target.value)}
                            className={wideCurrencySymbol() ? 'pl-10 tabular-nums' : 'pl-7 tabular-nums'}
                            aria-invalid={!!errors.amount || over}
                            autoFocus
                        />
                    </div>
                </Field>

                <Field id="credit-reason" label="Reason" error={errors.reason} hint="Printed on the invoice.">
                    <Textarea
                        id="credit-reason"
                        rows={2}
                        maxLength={500}
                        value={data.reason}
                        onChange={(event) => setData('reason', event.target.value)}
                        aria-invalid={!!errors.reason}
                    />
                </Field>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || amount === null || amount <= 0 || over || data.reason.trim() === ''}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {amount !== null && amount > 0 ? `Credit ${formatPence(amount)}` : 'Issue credit note'}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

export default CreditNoteDialog;

import { money } from '@/components/app/customers/format';
import { FormField } from '@/components/shared/form-section';
import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { FileArchive, LoaderCircle, ShieldAlert, UserRoundX } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { RequestStatus, RequestType, TillStepList, when } from './request-parts';
import { type CustomerPrivacyProps } from './types';

/**
 * The customer page's Privacy tab (owners only, module 7.7): download everything held about the customer (a subject
 * access request), anonymise them (right to erasure), and their past requests with any steps left on the tills.
 */
export function CustomerPrivacyPanel({ customerId, name, privacy }: { customerId: string; name: string; privacy: CustomerPrivacyProps }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="grid gap-6">
            <SectionCard
                title="Download their data"
                description="A ZIP with everything held about this customer: details, account and points history, marketing consent, linked sales and orders. As JSON, a readable PDF and CSVs. Nothing is kept on the server."
                actions={
                    <Button variant="outline" asChild>
                        <a href={route('app.privacy.customers.export', customerId)}>
                            <FileArchive />
                            Download ZIP
                        </a>
                    </Button>
                }
            >
                <p className="text-muted-foreground text-sm">Use this when a customer asks for a copy of their data. You have one month to reply.</p>
            </SectionCard>

            <SectionCard
                title="Anonymise this customer"
                description="Removes their name, phone, email, address, date of birth, card number and notes, here and on every till at its next sync. Sales, account and points history stay for your accounts, under an anonymous name. This cannot be undone."
                actions={
                    !privacy.anonymised && (
                        <Button variant="destructive" onClick={() => setOpen(true)} disabled={!privacy.settled}>
                            <UserRoundX />
                            Anonymise
                        </Button>
                    )
                }
            >
                {privacy.anonymised ? (
                    <p className="text-muted-foreground text-sm">This customer has been anonymised.</p>
                ) : privacy.settled ? (
                    <p className="text-muted-foreground text-sm">Any loyalty points left are lost. They will no longer receive marketing.</p>
                ) : (
                    <Alert variant="warning">
                        <ShieldAlert />
                        <AlertTitle>Settle their account first</AlertTitle>
                        <AlertDescription>
                            Their account balance is {money(Math.abs(Number(privacy.balance)))} {Number(privacy.balance) > 0 ? 'owed' : 'in credit'}.
                            Take the payment or refund the credit at a till, then anonymise them.
                        </AlertDescription>
                    </Alert>
                )}
            </SectionCard>

            {privacy.requests.length > 0 && (
                <SectionCard title="Requests" description="Data requests for this customer, newest first." flush>
                    <ul className="divide-border divide-y">
                        {privacy.requests.map((r) => (
                            <li key={r.id} className="grid gap-3 px-5 py-4 sm:px-6">
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                    <RequestType row={r} />
                                    <RequestStatus row={r} />
                                    <span className="text-muted-foreground">
                                        {when(r.createdAt)} · {r.requestedBy}
                                    </span>
                                </div>
                                {r.note && <p className="text-sm">{r.note}</p>}
                                <TillStepList row={r} />
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            )}

            <AnonymiseDialog customerId={customerId} name={name} open={open} onOpenChange={setOpen} />
        </div>
    );
}

function AnonymiseDialog({
    customerId,
    name,
    open,
    onOpenChange,
}: {
    customerId: string;
    name: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ confirm: '', note: '' });
    const error = (errors as Record<string, string | undefined>).customer;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('app.privacy.customers.anonymise', customerId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    reset();
                    clearErrors();
                }
                onOpenChange(next);
            }}
        >
            <DialogContent>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Anonymise {name}?</DialogTitle>
                        <DialogDescription>
                            Their personal details are removed here and on every till. Sales and account history stay, under an anonymous name. This
                            cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    {error && (
                        <Alert variant="destructive">
                            <ShieldAlert />
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                    <FormField
                        id="note"
                        label="Note"
                        optional
                        help="How they asked, or your reference. Do not include their name."
                        error={errors.note}
                    >
                        <Textarea id="note" value={data.note} maxLength={1000} onChange={(e) => setData('note', e.target.value)} />
                    </FormField>
                    <FormField id="confirm" label="Type ANONYMISE to confirm" error={errors.confirm}>
                        <Input
                            id="confirm"
                            autoComplete="off"
                            value={data.confirm}
                            aria-invalid={!!errors.confirm}
                            onChange={(e) => setData('confirm', e.target.value)}
                        />
                    </FormField>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing || data.confirm.toUpperCase() !== 'ANONYMISE'}>
                            {processing && <LoaderCircle className="animate-spin" />}
                            Anonymise customer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

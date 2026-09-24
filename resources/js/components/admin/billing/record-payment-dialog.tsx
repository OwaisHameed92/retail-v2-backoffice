import { formatDay, londonToday } from '@/components/admin/billing/format';
import { formatPence, fromPence, toPence } from '@/components/admin/billing/money';
import { type CompanyRef, type OpenInvoice, type Option, type PaymentMethod } from '@/components/admin/billing/types';
import { Field } from '@/components/admin/tenants/field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { sendJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { Banknote, CircleCheck, Landmark, LoaderCircle, Wallet } from 'lucide-react';
import { type FormEventHandler, useEffect, useMemo, useState } from 'react';

interface RecordPaymentDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    company: CompanyRef;
    methods: Option<PaymentMethod>[];
    /** Open invoices when the page already has them; fetched otherwise. */
    invoices?: OpenInvoice[];
    /** Put the payment on this invoice first (invoice page). */
    invoiceId?: string;
}

type Allocation = 'auto' | 'manual';

type FormData = {
    method: PaymentMethod;
    amount: string;
    received_on: string;
    reference: string;
    notes: string;
    allocation: Allocation;
    allocations: Record<string, string>;
};

const methodIcons: Partial<Record<PaymentMethod, typeof Banknote>> = { cash: Banknote, bankTransfer: Landmark, other: Wallet };

/** Record money received: amount, method, date, reference, and which invoices it pays (oldest first by default). */
export function RecordPaymentDialog(props: RecordPaymentDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <RecordPaymentBody {...props} />}
        </Dialog>
    );
}

function RecordPaymentBody({ onOpenChange, company, methods, invoices: given, invoiceId }: RecordPaymentDialogProps) {
    const [invoices, setInvoices] = useState<OpenInvoice[] | null>(given ?? null);
    const [loadError, setLoadError] = useState<string | null>(null);

    useEffect(() => {
        if (given) {
            return;
        }
        const controller = new AbortController();
        sendJson<{ invoices: OpenInvoice[] }>('GET', route('admin.billing.tenants.open-invoices', company.id), undefined, controller.signal)
            .then((result) => {
                if (result.ok && result.data) {
                    setInvoices(result.data.invoices);
                } else {
                    setLoadError(result.message ?? 'The open invoices could not be loaded.');
                }
            })
            .catch(() => undefined);

        return () => controller.abort();
    }, [company.id, given]);

    if (!invoices) {
        return (
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Record a payment</DialogTitle>
                    <DialogDescription>{company.name}</DialogDescription>
                </DialogHeader>
                {loadError ? (
                    <p className="text-danger-foreground text-sm">{loadError}</p>
                ) : (
                    <div className="grid gap-3" aria-busy>
                        <Skeleton className="h-9 w-full" />
                        <Skeleton className="h-20 w-full" />
                        <Skeleton className="h-9 w-2/3" />
                    </div>
                )}
            </DialogContent>
        );
    }

    return <PaymentForm company={company} methods={methods} invoices={invoices} invoiceId={invoiceId} onOpenChange={onOpenChange} />;
}

function PaymentForm({
    company,
    methods,
    invoices,
    invoiceId,
    onOpenChange,
}: Pick<RecordPaymentDialogProps, 'company' | 'methods' | 'invoiceId' | 'onOpenChange'> & { invoices: OpenInvoice[] }) {
    // The chosen invoice first, then oldest due first (same order the server allocates in).
    const ordered = useMemo(() => {
        const chosen = invoices.find((invoice) => invoice.id === invoiceId);

        return chosen ? [chosen, ...invoices.filter((invoice) => invoice.id !== invoiceId)] : invoices;
    }, [invoices, invoiceId]);

    const owed = ordered.reduce((sum, invoice) => sum + (toPence(invoice.balance) ?? 0), 0);
    const first = ordered[0];
    const startAmount = invoiceId && first ? first.balance : owed > 0 ? fromPence(owed) : '';
    const manualStart = invoiceId !== undefined && ordered.length > 1;

    const { data, setData, post, processing, errors, transform } = useForm<FormData>({
        method: methods[0]?.value ?? 'cash',
        amount: startAmount,
        received_on: londonToday(),
        reference: '',
        notes: '',
        allocation: manualStart ? 'manual' : 'auto',
        allocations: manualStart && first ? { [first.id]: first.balance } : {},
    });

    const amount = toPence(data.amount);

    // What the payment will do: pay invoices oldest first (auto) or the chosen amounts (manual).
    const plan = useMemo(() => {
        const rows: { invoice: OpenInvoice; pence: number; full: boolean }[] = [];
        if (amount === null) {
            return { rows, allocated: 0, credit: 0, over: false };
        }
        let left = amount;
        let allocated = 0;
        for (const invoice of ordered) {
            const balance = toPence(invoice.balance) ?? 0;
            let pence = 0;
            if (data.allocation === 'auto') {
                pence = Math.min(left, balance);
            } else if (data.allocations[invoice.id] !== undefined) {
                pence = Math.min(toPence(data.allocations[invoice.id]) ?? 0, balance);
            }
            if (pence > 0) {
                rows.push({ invoice, pence, full: pence === balance });
                left -= pence;
                allocated += pence;
            }
        }

        return { rows, allocated, credit: Math.max(0, amount - allocated), over: allocated > amount };
    }, [amount, data.allocation, data.allocations, ordered]);

    const toggle = (invoice: OpenInvoice, checked: boolean) => {
        const next = { ...data.allocations };
        if (checked) {
            const remaining = Math.max(0, (amount ?? 0) - plan.allocated);
            next[invoice.id] = fromPence(Math.min(remaining > 0 ? remaining : (toPence(invoice.balance) ?? 0), toPence(invoice.balance) ?? 0));
        } else {
            delete next[invoice.id];
        }
        setData('allocations', next);
    };

    transform((form) => ({ ...form, allocations: form.allocation === 'manual' ? form.allocations : {} }));

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('admin.billing.tenants.payments.store', company.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    const invalid = amount === null || amount <= 0 || plan.over;

    return (
        <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-xl" onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>Record a payment</DialogTitle>
                    <DialogDescription>
                        Money received from <span className="text-foreground font-medium">{company.name}</span>.{' '}
                        {owed > 0 ? `${formatPence(owed)} is owed on ${ordered.length === 1 ? '1 invoice' : `${ordered.length} invoices`}.` : 'Nothing is owed right now: the payment is kept as credit for the next invoice.'}
                    </DialogDescription>
                </DialogHeader>

                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">Paid by</legend>
                    <div className="grid grid-cols-3 gap-2">
                        {methods.map((option) => {
                            const Icon = methodIcons[option.value] ?? Wallet;
                            const selected = data.method === option.value;

                            return (
                                <label
                                    key={option.value}
                                    className={cn(
                                        'focus-within:ring-ring/40 flex cursor-pointer flex-col items-center gap-1.5 rounded-lg border px-2 py-3 text-center text-sm font-medium transition-colors focus-within:ring-2',
                                        selected ? 'border-primary bg-primary-soft text-foreground' : 'hover:bg-muted/60 text-muted-foreground',
                                    )}
                                >
                                    <input type="radio" name="method" value={option.value} checked={selected} onChange={() => setData('method', option.value)} className="sr-only" />
                                    <Icon className={cn('size-5', selected ? 'text-primary' : 'text-muted-foreground')} aria-hidden />
                                    {option.label}
                                </label>
                            );
                        })}
                    </div>
                    {errors.method && <p className="text-danger-foreground text-[13px]">{errors.method}</p>}
                </fieldset>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="payment-amount" label="Amount" error={errors.amount}>
                        <div className="relative">
                            <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm">£</span>
                            <Input
                                id="payment-amount"
                                inputMode="decimal"
                                autoComplete="off"
                                value={data.amount}
                                onChange={(event) => setData('amount', event.target.value)}
                                className="pl-7 tabular-nums"
                                aria-invalid={!!errors.amount || (data.amount !== '' && amount === null)}
                                autoFocus
                            />
                        </div>
                    </Field>
                    <Field id="payment-date" label="Received on" error={errors.received_on}>
                        <Input
                            id="payment-date"
                            type="date"
                            max={londonToday()}
                            value={data.received_on}
                            onChange={(event) => setData('received_on', event.target.value)}
                            aria-invalid={!!errors.received_on}
                        />
                    </Field>
                </div>

                <Field id="payment-reference" label="Reference" optional error={errors.reference} hint="Receipt number, bank reference or who handed it over.">
                    <Input id="payment-reference" value={data.reference} maxLength={100} onChange={(event) => setData('reference', event.target.value)} />
                </Field>

                {ordered.length > 0 && (
                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">Put it towards</legend>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {(['auto', 'manual'] as const).map((mode) => (
                                <label
                                    key={mode}
                                    className={cn(
                                        'focus-within:ring-ring/40 flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors focus-within:ring-2',
                                        data.allocation === mode ? 'border-primary bg-primary-soft' : 'hover:bg-muted/50',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="allocation"
                                        value={mode}
                                        checked={data.allocation === mode}
                                        onChange={() => setData('allocation', mode)}
                                        className="accent-primary mt-1"
                                    />
                                    <span className="grid gap-0.5">
                                        <span className="text-sm font-medium">{mode === 'auto' ? 'Oldest invoice first' : 'Choose invoices'}</span>
                                        <span className="text-muted-foreground text-[13px]">
                                            {mode === 'auto' ? 'Recommended. Anything left is kept as credit.' : 'Set how much goes on each invoice.'}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>

                        <ul className="divide-y rounded-lg border" aria-label="Open invoices">
                            {ordered.map((invoice) => {
                                const row = plan.rows.find((item) => item.invoice.id === invoice.id);
                                const checked = data.allocations[invoice.id] !== undefined;

                                return (
                                    <li key={invoice.id} className="flex items-center gap-3 px-3 py-2.5 text-sm">
                                        {data.allocation === 'manual' && (
                                            <Checkbox
                                                checked={checked}
                                                onCheckedChange={(value) => toggle(invoice, value === true)}
                                                aria-label={`Pay ${invoice.number ?? 'invoice'}`}
                                            />
                                        )}
                                        <div className="min-w-0 flex-1 leading-tight">
                                            <div className="flex items-center gap-2">
                                                <span className="font-mono text-[13px] font-medium">{invoice.number}</span>
                                                {invoice.status === 'overdue' && <span className="text-danger-foreground text-xs font-medium">Overdue</span>}
                                            </div>
                                            <div className="text-muted-foreground truncate text-xs">
                                                {invoice.period} · due {formatDay(invoice.dueDate)} · owes {invoice.balanceLabel}
                                            </div>
                                        </div>
                                        {data.allocation === 'manual' ? (
                                            <div className="relative w-28 shrink-0">
                                                <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-sm">£</span>
                                                <Input
                                                    inputMode="decimal"
                                                    disabled={!checked}
                                                    value={data.allocations[invoice.id] ?? ''}
                                                    onChange={(event) => setData('allocations', { ...data.allocations, [invoice.id]: event.target.value })}
                                                    className="h-8 pl-6 text-right tabular-nums"
                                                    aria-label={`Amount for ${invoice.number ?? 'invoice'}`}
                                                />
                                            </div>
                                        ) : (
                                            <span className={cn('shrink-0 tabular-nums', row ? 'text-foreground font-medium' : 'text-muted-foreground')}>
                                                {row ? formatPence(row.pence) : '—'}
                                            </span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                        {errors.allocations && <p className="text-danger-foreground text-[13px]">{errors.allocations}</p>}
                    </fieldset>
                )}

                {amount !== null && amount > 0 && (
                    <div
                        className={cn(
                            'flex items-start gap-2 rounded-lg px-3 py-2.5 text-sm',
                            plan.over ? 'bg-danger-soft text-danger-foreground' : 'bg-success-soft text-success-foreground',
                        )}
                        role="status"
                    >
                        <CircleCheck className="mt-0.5 size-4 shrink-0" aria-hidden />
                        <span>
                            {plan.over
                                ? `The amounts on invoices add up to ${formatPence(plan.allocated)}, more than the ${formatPence(amount)} received.`
                                : [
                                      plan.rows.filter((row) => row.full).length > 0 &&
                                          `Pays ${plan.rows
                                              .filter((row) => row.full)
                                              .map((row) => row.invoice.number)
                                              .join(', ')} in full and renews those tills.`,
                                      plan.rows.filter((row) => !row.full).length > 0 &&
                                          plan.rows
                                              .filter((row) => !row.full)
                                              .map((row) => `${formatPence(row.pence)} towards ${row.invoice.number}.`)
                                              .join(' '),
                                      plan.credit > 0 && `${formatPence(plan.credit)} kept as credit.`,
                                  ]
                                      .filter(Boolean)
                                      .join(' ')}
                        </span>
                    </div>
                )}

                <Field id="payment-notes" label="Notes" optional error={errors.notes}>
                    <Textarea id="payment-notes" rows={2} value={data.notes} maxLength={2000} onChange={(event) => setData('notes', event.target.value)} />
                </Field>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || invalid}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {amount !== null && amount > 0 ? `Record ${formatPence(amount)}` : 'Record payment'}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

export default RecordPaymentDialog;

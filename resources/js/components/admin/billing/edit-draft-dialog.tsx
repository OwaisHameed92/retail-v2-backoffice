import { formatPence, toPence } from '@/components/admin/billing/money';
import { type InvoiceDetail } from '@/components/admin/billing/types';
import { Field } from '@/components/admin/tenants/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { KeyRound, LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface EditDraftDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoice: Pick<InvoiceDetail, 'id' | 'lines' | 'notes' | 'vatRate' | 'period'>;
}

type LineInput = {
    id: string | null;
    description: string;
    quantity: string;
    unit_price: string;
    hasLicence: boolean;
};

/** Quantity × unit price in pence, rounded half away from zero like the server (quantities up to 4 dp). */
function linePence(quantity: string, unitPrice: string): number | null {
    const q = /^\d{1,4}(\.\d{1,4})?$/.test(quantity.trim()) ? Math.round(Number(quantity) * 10_000) : null;
    const negative = unitPrice.trim().startsWith('-');
    const price = toPence(unitPrice.trim().replace(/^-/, ''));
    if (q === null || price === null) {
        return null;
    }
    const raw = (q * price) / 10_000;

    return (negative ? -1 : 1) * Math.round(raw);
}

/** Edit a draft's lines and notes before issuing. Lines from tills keep their licence (paid → renewed). */
export function EditDraftDialog(props: EditDraftDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <EditBody {...props} />}
        </Dialog>
    );
}

function EditBody({ onOpenChange, invoice }: EditDraftDialogProps) {
    const { data, setData, put, processing, errors } = useForm<{ notes: string; lines: LineInput[] }>({
        notes: invoice.notes ?? '',
        lines: invoice.lines.map((line) => ({ id: line.id, description: line.description, quantity: line.quantity, unit_price: line.unitPrice, hasLicence: line.hasLicence })),
    });

    const update = (index: number, patch: Partial<LineInput>) => setData('lines', data.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)));
    const remove = (index: number) => setData('lines', data.lines.filter((_, i) => i !== index));
    const add = () => setData('lines', [...data.lines, { id: null, description: '', quantity: '1', unit_price: '', hasLicence: false }]);

    const net = data.lines.reduce<number | null>((sum, line) => {
        const pence = linePence(line.quantity, line.unit_price);

        return sum === null || pence === null ? null : sum + pence;
    }, 0);

    const lineError = (index: number, field: string) => errors[`lines.${index}.${field}` as keyof typeof errors];

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        put(route('admin.billing.invoices.update', invoice.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl" onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>Edit draft invoice</DialogTitle>
                    <DialogDescription>
                        {invoice.period}. Amounts are before VAT{invoice.vatRate !== '0.00' ? `; VAT is added at ${invoice.vatRate.replace(/\.?0+$/, '')}%` : ''}. A negative price makes a
                        discount line.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-3">
                    <div className="text-muted-foreground hidden grid-cols-[minmax(0,1fr)_5.5rem_7.5rem_6rem_2.25rem] gap-2 px-1 text-xs font-medium tracking-wide uppercase md:grid">
                        <span>Description</span>
                        <span className="text-right">Qty</span>
                        <span className="text-right">Unit price</span>
                        <span className="text-right">Net</span>
                        <span />
                    </div>
                    {data.lines.map((line, index) => {
                        const pence = linePence(line.quantity, line.unit_price);

                        return (
                            <div key={line.id ?? `new-${index}`} className="grid grid-cols-1 gap-2 rounded-lg border p-3 md:grid-cols-[minmax(0,1fr)_5.5rem_7.5rem_6rem_2.25rem] md:items-start md:border-0 md:p-0">
                                <div className="grid gap-1">
                                    <div className="relative">
                                        {line.hasLicence && (
                                            <KeyRound className="text-muted-foreground absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2" aria-label="Renews a till’s licence when paid" />
                                        )}
                                        <Input
                                            value={line.description}
                                            maxLength={500}
                                            onChange={(event) => update(index, { description: event.target.value })}
                                            aria-label={`Line ${index + 1} description`}
                                            aria-invalid={!!lineError(index, 'description')}
                                            className={cn(line.hasLicence && 'pl-7')}
                                        />
                                    </div>
                                    {lineError(index, 'description') && <p className="text-danger-foreground text-[13px]">{lineError(index, 'description')}</p>}
                                </div>
                                <div className="grid gap-1">
                                    <Input
                                        inputMode="decimal"
                                        value={line.quantity}
                                        onChange={(event) => update(index, { quantity: event.target.value })}
                                        aria-label={`Line ${index + 1} quantity`}
                                        aria-invalid={!!lineError(index, 'quantity')}
                                        className="text-right tabular-nums"
                                    />
                                    {lineError(index, 'quantity') && <p className="text-danger-foreground text-[13px]">{lineError(index, 'quantity')}</p>}
                                </div>
                                <div className="grid gap-1">
                                    <div className="relative">
                                        <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-sm">£</span>
                                        <Input
                                            inputMode="decimal"
                                            value={line.unit_price}
                                            onChange={(event) => update(index, { unit_price: event.target.value })}
                                            aria-label={`Line ${index + 1} unit price`}
                                            aria-invalid={!!lineError(index, 'unit_price')}
                                            className="pl-6 text-right tabular-nums"
                                        />
                                    </div>
                                    {lineError(index, 'unit_price') && <p className="text-danger-foreground text-[13px]">{lineError(index, 'unit_price')}</p>}
                                </div>
                                <div className="flex h-9 items-center justify-end text-sm tabular-nums">{pence === null ? '—' : formatPence(pence)}</div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="text-muted-foreground hover:text-destructive size-9 justify-self-end"
                                    onClick={() => remove(index)}
                                    disabled={data.lines.length === 1}
                                    aria-label={`Remove line ${index + 1}`}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        );
                    })}
                    {errors.lines && <p className="text-danger-foreground text-[13px]">{errors.lines}</p>}
                    <div className="flex items-center justify-between gap-3">
                        <Button type="button" variant="outline" size="sm" onClick={add} disabled={data.lines.length >= 100}>
                            <Plus />
                            Add line
                        </Button>
                        <p className="text-sm">
                            <span className="text-muted-foreground">Net total </span>
                            <span className={cn('font-semibold tabular-nums', net !== null && net < 0 && 'text-destructive')}>{net === null ? '—' : formatPence(net)}</span>
                        </p>
                    </div>
                </div>

                <Field id="draft-notes" label="Notes on the invoice" optional error={errors.notes}>
                    <Textarea id="draft-notes" rows={2} maxLength={2000} value={data.notes} onChange={(event) => setData('notes', event.target.value)} />
                </Field>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || net === null || net < 0}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Save draft
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

export default EditDraftDialog;

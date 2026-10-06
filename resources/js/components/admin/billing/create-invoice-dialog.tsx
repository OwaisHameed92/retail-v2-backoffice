import { formatDay } from '@/components/admin/billing/format';
import { type BillingCycle, type CompanyRef, type InvoicePreview, type Option } from '@/components/admin/billing/types';
import { Field } from '@/components/admin/tenants/field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { taxName } from '@/lib/country';
import { sendJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useEffect, useState } from 'react';

interface CreateInvoiceDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    company: CompanyRef;
    /** Suggested first day of the next period ("YYYY-MM-DD"). Omit to ask the server. */
    periodStart?: string;
    /** The company's billing cycle. Omit to ask the server. */
    cycle?: BillingCycle;
    cycles: Option<BillingCycle>[];
}

type FormData = {
    period_start: string;
    cycle: BillingCycle | '';
    prorate: boolean;
    notes: string;
    issue: boolean;
    allow_overlap: boolean;
};

/** Create a tenant's invoice for a period: live preview of the lines and totals, then a draft or issue now. */
export function CreateInvoiceDialog(props: CreateInvoiceDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <CreateInvoiceBody {...props} />}
        </Dialog>
    );
}

function CreateInvoiceBody({ onOpenChange, company, periodStart, cycle, cycles }: CreateInvoiceDialogProps) {
    const { data, setData, post, processing, errors } = useForm<FormData>({
        period_start: periodStart ?? '',
        cycle: cycle ?? '',
        prorate: false,
        notes: '',
        issue: false,
        allow_overlap: false,
    });
    const [preview, setPreview] = useState<InvoicePreview | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        // An empty start asks the server for the company's next period (and its cycle).
        if (data.period_start !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(data.period_start)) {
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            const query = new URLSearchParams({ prorate: data.prorate ? '1' : '0' });
            if (data.period_start !== '') {
                query.set('period_start', data.period_start);
            }
            if (data.cycle) {
                query.set('cycle', data.cycle);
            }
            try {
                const result = await sendJson<InvoicePreview>('GET', `${route('admin.billing.tenants.invoice-preview', company.id)}?${query}`, undefined, controller.signal);
                setPreview(result.ok ? result.data : null);
                setPreviewError(result.ok ? null : (Object.values(result.errors)[0] ?? result.message));
                setLoading(false);
                if (result.ok && result.data && data.period_start === '') {
                    setData((current) => ({ ...current, period_start: result.data!.periodStart, cycle: result.data!.cycle }));
                }
            } catch {
                // Superseded by a newer preview.
            }
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
        // setData is stable enough for this effect; re-running on it would refetch needlessly.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [company.id, data.period_start, data.cycle, data.prorate]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('admin.billing.tenants.invoices.store', company.id), { onSuccess: () => onOpenChange(false) });
    };

    const blocked = !preview || preview.lines.length === 0 || (preview.overlaps !== null && !data.allow_overlap);

    return (
        <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>Create invoice</DialogTitle>
                    <DialogDescription>
                        One line per active till of <span className="text-foreground font-medium">{company.name}</span>, at its plan price.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field id="invoice-start" label="Period starts" error={errors.period_start} hint={preview ? `Runs to ${formatDay(preview.periodEnd)}.` : undefined}>
                        <Input
                            id="invoice-start"
                            type="date"
                            value={data.period_start}
                            onChange={(event) => setData('period_start', event.target.value)}
                            aria-invalid={!!errors.period_start}
                        />
                    </Field>
                    <Field id="invoice-cycle" label="Billing" error={errors.cycle}>
                        <Select value={data.cycle || undefined} onValueChange={(value) => setData('cycle', value as BillingCycle)}>
                            <SelectTrigger id="invoice-cycle">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {cycles.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                </div>

                <div className="flex items-start gap-3">
                    <Checkbox id="invoice-prorate" checked={data.prorate} onCheckedChange={(checked) => setData('prorate', checked === true)} className="mt-0.5" />
                    <div className="grid gap-1">
                        <Label htmlFor="invoice-prorate">Charge part-periods</Label>
                        <p className="text-muted-foreground text-sm">Tills already paid or on trial into this period are charged only for the days after.</p>
                    </div>
                </div>

                <section aria-label="Preview" aria-busy={loading} className="overflow-hidden rounded-lg border">
                    <div className="bg-subtle flex items-center justify-between gap-3 border-b px-3 py-2 text-[13px]">
                        <span className="font-medium">{preview ? preview.period : 'Preview'}</span>
                        {preview && <span className="text-muted-foreground">{preview.lines.length === 1 ? '1 till' : `${preview.lines.length} tills`}</span>}
                    </div>
                    {loading && !preview ? (
                        <div className="grid gap-2 p-3">
                            <Skeleton className="h-4 w-full" />
                            <Skeleton className="h-4 w-5/6" />
                            <Skeleton className="h-4 w-2/3" />
                        </div>
                    ) : previewError ? (
                        <p className="text-danger-foreground p-3 text-sm">{previewError}</p>
                    ) : preview && preview.lines.length === 0 ? (
                        <p className="text-muted-foreground p-3 text-sm">No active till needs billing for this period.</p>
                    ) : preview ? (
                        <div className={cn('transition-opacity', loading && 'opacity-60')}>
                            <ul className="max-h-48 divide-y overflow-y-auto">
                                {preview.lines.map((line, index) => (
                                    <li key={index} className="flex items-start justify-between gap-3 px-3 py-2 text-sm">
                                        <span className="min-w-0">{line.description}</span>
                                        <span className="shrink-0 tabular-nums">{line.gross}</span>
                                    </li>
                                ))}
                            </ul>
                            <dl className="grid gap-1 border-t px-3 py-2.5 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Subtotal</dt>
                                    <dd className="tabular-nums">{preview.subtotal}</dd>
                                </div>
                                {preview.hasVat && (
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">
                                            {taxName()} at {preview.vatRate}
                                        </dt>
                                        <dd className="tabular-nums">{preview.vatTotal}</dd>
                                    </div>
                                )}
                                <div className="flex justify-between font-semibold">
                                    <dt>Total</dt>
                                    <dd className="tabular-nums">{preview.total}</dd>
                                </div>
                            </dl>
                        </div>
                    ) : null}
                </section>

                {preview?.overlaps && (
                    <div className="border-warning/40 bg-warning-soft text-warning-foreground grid gap-2 rounded-lg border px-3 py-2.5 text-sm">
                        <p className="flex items-start gap-2">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                            {preview.overlaps} already covers part of this period.
                        </p>
                        <label className="flex items-center gap-2 font-medium">
                            <Checkbox checked={data.allow_overlap} onCheckedChange={(checked) => setData('allow_overlap', checked === true)} />
                            Create an extra invoice anyway
                        </label>
                    </div>
                )}

                <Field id="invoice-notes" label="Notes on the invoice" optional error={errors.notes}>
                    <Textarea id="invoice-notes" rows={2} maxLength={2000} value={data.notes} onChange={(event) => setData('notes', event.target.value)} />
                </Field>

                <div className="flex items-start gap-3">
                    <Checkbox id="invoice-issue" checked={data.issue} onCheckedChange={(checked) => setData('issue', checked === true)} className="mt-0.5" />
                    <div className="grid gap-1">
                        <Label htmlFor="invoice-issue">Issue and email it now</Label>
                        <p className="text-muted-foreground text-sm">Otherwise it is saved as a draft to check and edit first.</p>
                    </div>
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || blocked}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {data.issue ? 'Issue invoice' : 'Create draft'}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

export default CreateInvoiceDialog;

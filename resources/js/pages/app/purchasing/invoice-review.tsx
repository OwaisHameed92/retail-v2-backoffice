import { cost, formatDateTime, money } from '@/components/app/purchasing/format';
import { ChecksCard } from '@/components/app/purchasing/invoice-import/checks-card';
import { ConfirmCard } from '@/components/app/purchasing/invoice-import/confirm-card';
import { HeaderFields } from '@/components/app/purchasing/invoice-import/header-fields';
import { LinesEditor } from '@/components/app/purchasing/invoice-import/lines-editor';
import { IMPORT_TONES, type InvoiceDraft, type InvoiceReviewProps } from '@/components/app/purchasing/invoice-import/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { taxName, taxText } from '@/lib/country';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CircleCheck, ExternalLink, FileText, LoaderCircle, RotateCcw, Save, Trash2, TriangleAlert } from 'lucide-react';
import { useEffect } from 'react';

const BLANK: InvoiceDraft = {
    documentType: 'invoice',
    supplierName: null,
    supplierVatNumber: null,
    supplierId: null,
    supplierPinned: false,
    invoiceNumber: null,
    invoiceDate: null,
    orderReference: null,
    purchaseOrderId: null,
    goodsReceiptId: null,
    documentPinned: false,
    netTotal: null,
    vatTotal: null,
    grossTotal: null,
    lines: [],
    readingNotes: [],
};

/** The draft, the lines, the checks and Confirm. Keyed by the saved draft so a save shows the new matches. */
function ReviewForm(props: InvoiceReviewProps) {
    const { draft, analysis, can, suppliers, orders, deliveries, results, search } = props;
    const form = useForm<InvoiceDraft>(draft ?? BLANK);
    const errors = form.errors as Record<string, string>;
    const editable = can.edit;

    const save = () => form.put(route('app.purchasing.invoices.import.update', props.import.id), { preserveScroll: true });

    return (
        <form
            className="grid gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                save();
            }}
        >
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px] xl:items-start">
                <div className="grid min-w-0 gap-4">
                    <HeaderFields
                        data={form.data}
                        editable={editable}
                        errors={errors}
                        suppliers={suppliers}
                        orders={orders}
                        deliveries={deliveries}
                        onChange={(patch) => form.setData({ ...form.data, ...patch })}
                    />
                    <SectionCard
                        title={`Lines (${form.data.lines.length})`}
                        description={errors.lines ?? taxText('Quantity of what is sold × items in each, at the price ex VAT. Change a match with Pick.')}
                        flush
                    >
                        <LinesEditor
                            lines={form.data.lines}
                            analysis={analysis}
                            issues={analysis?.issues ?? []}
                            editable={editable}
                            errors={errors}
                            results={results}
                            search={search}
                            onChange={(lines) => form.setData('lines', lines)}
                        />
                    </SectionCard>
                </div>
                <div className="grid gap-4 xl:sticky xl:top-4">
                    {analysis && <ChecksCard analysis={analysis} draft={draft ?? BLANK} />}
                    {can.confirm && <ConfirmCard props={props} dirty={form.isDirty} />}
                </div>
            </div>

            {editable && (
                <StickyFormBar message={form.isDirty ? 'You have changes. Save to match and check again.' : 'Saved. Change anything and save to check again.'}>
                    <Button type="submit" disabled={form.processing || !form.isDirty}>
                        {form.processing ? <LoaderCircle className="animate-spin" /> : <Save />}
                        Save and check
                    </Button>
                </StickyFormBar>
            )}
        </form>
    );
}

function Confirmed({ props }: { props: InvoiceReviewProps }) {
    const result = props.import.result;

    return (
        <SectionCard title="Confirmed" description={props.import.confirmedAt ? `On ${formatDateTime(props.import.confirmedAt)}.` : undefined}>
            <ul className="grid gap-2 text-sm">
                <li className="flex items-center gap-2">
                    <CircleCheck className="text-success size-4" />
                    Recorded: {result?.lines ?? 0} lines, {money(result?.net ?? '0')} net, {money(result?.gross ?? '0')} with {taxName()}.
                </li>
                {result?.order && (
                    <li className="flex items-center gap-2">
                        <CircleCheck className="text-success size-4" />
                        Head-office order
                        <Link href={route('app.purchasing.orders.show', result.order.id)} className="text-primary font-medium hover:underline">
                            {result.order.reference}
                        </Link>
                        ({result.order.status === 'sent' ? 'sent' : 'draft'}, {result.order.lines} lines)
                    </li>
                )}
                {(result?.costUpdates ?? []).map((c) => (
                    <li key={c.productId} className="flex items-center gap-2">
                        <CircleCheck className="text-success size-4" />
                        {c.name}: cost {cost(c.from)} → {cost(c.to)}
                    </li>
                ))}
            </ul>
        </SectionCard>
    );
}

/** Review one imported invoice (module 6.5): fix what was read, check the sums and matches, then confirm. */
export default function InvoiceReview(props: InvoiceReviewProps) {
    const { import: record, can, draft } = props;
    const reading = record.status === 'reading';

    // Poll while the model reads the document.
    useEffect(() => {
        if (!reading) {
            return;
        }
        const timer = setInterval(() => router.reload(), 3000);

        return () => clearInterval(timer);
    }, [reading]);

    const title = draft?.invoiceNumber ? `Invoice ${draft.invoiceNumber}` : 'Imported invoice';
    const meta = [
        record.shop.name,
        record.uploadedBy ? `uploaded by ${record.uploadedBy}` : null,
        record.createdAt ? formatDateTime(record.createdAt) : null,
        record.method === 'manual' ? 'entered by hand' : 'read automatically',
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <AppLayout>
            <Head title={`${title} · Invoice import`} />

            <PageHeader
                title={title}
                icon={FileText}
                status={<StatusBadge status={record.status} label={record.statusLabel} tones={IMPORT_TONES} />}
                back={{ href: route('app.purchasing.invoices.import.index'), label: 'Invoice import' }}
                description={meta}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {record.file && (
                            <Button variant="outline" asChild>
                                <a href={route('app.purchasing.invoices.import.file', record.id)} target="_blank" rel="noopener noreferrer">
                                    <FileText />
                                    View file
                                    <ExternalLink className="size-3.5" />
                                </a>
                            </Button>
                        )}
                        {can.discard && (
                            <ConfirmDialog
                                trigger={
                                    <Button variant="outline">
                                        <Trash2 />
                                        Discard
                                    </Button>
                                }
                                title="Discard this import?"
                                description="The uploaded file is deleted now. Nothing was created from it."
                                confirmLabel="Discard import"
                                destructive
                                onConfirm={() => new Promise<void>((resolve) => router.post(route('app.purchasing.invoices.import.discard', record.id), {}, { onFinish: () => resolve() }))}
                            />
                        )}
                    </div>
                }
            />

            {record.filePurged && (
                <Alert variant="info">
                    <FileText />
                    <AlertDescription>The uploaded file was deleted after the retention period. The figures stay.</AlertDescription>
                </Alert>
            )}

            {reading && (
                <SectionCard>
                    <div className="flex flex-col items-center gap-3 py-10 text-center" role="status" aria-live="polite">
                        <LoaderCircle className="text-primary size-8 animate-spin" />
                        <p className="font-medium">Reading {record.file?.name ?? 'the invoice'}…</p>
                        <p className="text-muted-foreground max-w-md text-sm">
                            This usually takes under a minute. You can leave this page; the import waits for you in the list.
                        </p>
                    </div>
                </SectionCard>
            )}

            {record.status === 'failed' && (
                <Alert variant="destructive">
                    <TriangleAlert />
                    <AlertDescription className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <span>{record.error ?? 'We could not read this document.'} You can enter it by hand below.</span>
                        {can.retry && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => router.post(route('app.purchasing.invoices.import.retry', record.id), {}, { preserveScroll: true })}
                            >
                                <RotateCcw />
                                Read again
                            </Button>
                        )}
                    </AlertDescription>
                </Alert>
            )}

            {record.status === 'discarded' && (
                <Alert variant="info">
                    <Trash2 />
                    <AlertDescription>This import was discarded and its file deleted.</AlertDescription>
                </Alert>
            )}

            {record.status === 'confirmed' && <Confirmed props={props} />}

            {!reading && record.status !== 'discarded' && <ReviewForm key={JSON.stringify(draft)} {...props} />}
        </AppLayout>
    );
}

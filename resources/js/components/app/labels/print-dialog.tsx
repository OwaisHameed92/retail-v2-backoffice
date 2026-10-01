import { OptionSelect } from '@/components/app/products/fields';
import { FormField, FormGrid } from '@/components/shared/form-section';
import { showToast } from '@/components/shared/toaster';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { postAndDownload, sendJson } from '@/lib/http';
import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Download, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { LabelSheet } from './label-sheet';
import { type LabelPreview, type LabelTemplate } from './types';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    shopId: string;
    /** The labels to print, or 'all' for every label waiting in the shop. */
    ids: string[] | 'all';
    templates: LabelTemplate[];
    /** Printing labels that are waiting (not reprints): offer to take them off the queue. */
    waiting: boolean;
    onPrinted: () => void;
}

/** Preview and download shelf labels as a PDF, on the chosen template's stock (gap #6). */
export function PrintDialog({ open, onOpenChange, shopId, ids, templates, waiting, onPrinted }: Props) {
    const [templateId, setTemplateId] = useState(templates[0]?.id ?? 'builtin');
    const [skip, setSkip] = useState('0');
    const [markPrinted, setMarkPrinted] = useState(true);
    const [preview, setPreview] = useState<LabelPreview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [page, setPage] = useState(0);
    const [downloading, setDownloading] = useState(false);

    const body = useMemo(
        () => ({ branch_id: shopId, ...(ids === 'all' ? { all: true } : { ids }), template_id: templateId, skip: Number(skip) || 0 }),
        [shopId, ids, templateId, skip],
    );

    useEffect(() => {
        if (open) {
            setTemplateId(templates[0]?.id ?? 'builtin');
            setSkip('0');
            setMarkPrinted(waiting);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => {
        if (!open) {
            return;
        }
        const controller = new AbortController();
        setError(null);
        setPreview(null);
        setPage(0);
        sendJson<LabelPreview>('POST', route('app.labels.preview'), body, controller.signal)
            .then((result) => (result.ok ? setPreview(result.data) : setError(result.message)))
            .catch(() => undefined);

        return () => controller.abort();
    }, [open, body]);

    const expanded = useMemo(() => (preview ? preview.labels.flatMap((label) => Array.from({ length: label.copies }, () => label)) : []), [preview]);
    const perPage = preview ? preview.stock.perPage : 1;
    const firstOnPage = page === 0 ? 0 : page * perPage - (preview?.skip ?? 0);
    const pageLabels = expanded.slice(firstOnPage, page === 0 ? perPage - (preview?.skip ?? 0) : firstOnPage + perPage);

    const download = async () => {
        setDownloading(true);
        const message = await postAndDownload(route('app.labels.pdf'), { ...body, mark_printed: waiting && markPrinted }, 'shelf-labels.pdf');
        setDownloading(false);
        if (message) {
            showToast(message, 'error');
            return;
        }
        showToast(waiting && markPrinted ? 'PDF downloaded. The labels are marked as printed.' : 'PDF downloaded.');
        onOpenChange(false);
        if (waiting && markPrinted) {
            onPrinted();
            router.reload({ only: ['items', 'counts'] });
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Print shelf labels</DialogTitle>
                    <DialogDescription>Check the preview, then download the PDF and print it at 100% (actual size), not "fit to page".</DialogDescription>
                </DialogHeader>

                <FormGrid>
                    <FormField id="template" label="Template">
                        <OptionSelect
                            id="template"
                            value={templateId}
                            options={templates.map((t) => ({ value: t.id, label: t.isDefault ? `${t.name} (default)` : t.name }))}
                            onChange={setTemplateId}
                        />
                    </FormField>
                    {preview?.stock.kind !== 'roll' && (
                        <FormField id="skip" label="Labels already used on the first sheet" optional help="They are left empty, so you can use up a part-used sheet.">
                            <Input id="skip" type="number" inputMode="numeric" min={0} max={perPage - 1} value={skip} onChange={(e) => setSkip(e.target.value)} />
                        </FormField>
                    )}
                </FormGrid>

                <div className="bg-muted/50 grid gap-3 rounded-lg border p-3 sm:p-4">
                    {error ? (
                        <Alert variant="destructive">
                            <TriangleAlert />
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    ) : !preview ? (
                        <Skeleton className="mx-auto aspect-[210/297] w-full max-w-sm" />
                    ) : (
                        <>
                            <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span>
                                    {preview.count} {preview.count === 1 ? 'label' : 'labels'} on {preview.stock.name}
                                    {preview.stock.ref ? ` (${preview.stock.ref} size)` : ''}
                                </span>
                                {preview.pages > 1 && (
                                    <span className="flex items-center gap-1">
                                        <Button size="icon" variant="ghost" className="size-8" disabled={page === 0} onClick={() => setPage(page - 1)} aria-label="Previous page">
                                            <ChevronLeft className="size-4" />
                                        </Button>
                                        <span className="tabular-nums">
                                            Page {page + 1} of {preview.pages}
                                        </span>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-8"
                                            disabled={page >= preview.pages - 1}
                                            onClick={() => setPage(page + 1)}
                                            aria-label="Next page"
                                        >
                                            <ChevronRight className="size-4" />
                                        </Button>
                                    </span>
                                )}
                            </div>
                            <div className={preview.stock.kind === 'roll' ? 'mx-auto w-full max-w-xs' : 'mx-auto w-full max-w-md'}>
                                <LabelSheet stock={preview.stock} options={preview.template.options} labels={pageLabels} skip={page === 0 ? preview.skip : 0} />
                            </div>
                        </>
                    )}
                </div>

                {waiting && (
                    <div className="flex items-start gap-2">
                        <Checkbox id="mark_printed" checked={markPrinted} onCheckedChange={(checked) => setMarkPrinted(checked === true)} />
                        <Label htmlFor="mark_printed" className="leading-5 font-normal">
                            Mark these labels as printed when the PDF downloads (they leave the queue until the price changes again)
                        </Label>
                    </div>
                )}

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button type="button" onClick={download} disabled={!preview || downloading}>
                        {downloading ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <Download className="size-4" aria-hidden />}
                        Download PDF
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

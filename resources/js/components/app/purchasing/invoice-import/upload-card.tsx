import { OptionSelect } from '@/components/app/products/fields';
import { FormField } from '@/components/shared/form-section';
import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { FileUp, Info, LoaderCircle, PencilLine, Sparkles, X } from 'lucide-react';
import { useRef, useState, type DragEvent } from 'react';
import { type InvoiceImportProps } from './types';

const ACCEPT = 'application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png';
const TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

function size(bytes: number): string {
    return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** Choose the shop and the file, then have it read (or enter it by hand). */
export function UploadCard({ access, shops, limits }: Pick<InvoiceImportProps, 'access' | 'shops' | 'limits'>) {
    const form = useForm<{ shopId: string; file: File | null; manual: boolean }>({ shopId: shops.length === 1 ? shops[0].id : '', file: null, manual: false });
    const [dragging, setDragging] = useState(false);
    const [clientError, setClientError] = useState<string | null>(null);
    const input = useRef<HTMLInputElement>(null);
    const canRead = access.reader.available;
    const errors = form.errors as Record<string, string>;

    const choose = (file: File | null | undefined) => {
        setClientError(null);
        if (!file) {
            return;
        }
        if (!TYPES.includes(file.type)) {
            setClientError('Upload a PDF, JPG or PNG.');
            return;
        }
        if (file.size > limits.maxKb * 1024) {
            setClientError('The file is larger than 10 MB. Upload a smaller scan or photo.');
            return;
        }
        form.setData('file', file);
    };

    const submit = (manual: boolean) => {
        form.transform((data) => ({ ...data, manual }));
        form.post(route('app.purchasing.invoices.import.store'), { forceFormData: true, preserveScroll: true });
    };

    const onDrop = (event: DragEvent<HTMLLabelElement>) => {
        event.preventDefault();
        setDragging(false);
        choose(event.dataTransfer.files?.[0]);
    };

    return (
        <SectionCard
            title="Upload an invoice or delivery note"
            description={`A PDF, or a photo (JPG or PNG), up to 10 MB. Files are kept privately for ${limits.retentionDays} days.`}
        >
            <div className="grid gap-4">
                {!canRead && (
                    <Alert variant="warning">
                        <Info />
                        <AlertDescription>
                            {access.reader.message ?? 'Reading invoices automatically is not available right now.'} You can still enter the invoice by
                            hand: it is checked against your products and orders in the same way.
                        </AlertDescription>
                    </Alert>
                )}

                {shops.length > 1 && (
                    <FormField id="shopId" label="Shop" error={errors.shopId} help="The shop the goods were delivered to." className="sm:max-w-sm">
                        <OptionSelect
                            id="shopId"
                            value={form.data.shopId}
                            invalid={Boolean(errors.shopId)}
                            onChange={(value) => form.setData('shopId', value)}
                            options={shops.map((s) => ({ value: s.id, label: `${s.name} (${s.code})` }))}
                            placeholder="Choose a shop"
                        />
                    </FormField>
                )}

                <label
                    htmlFor="invoice-file"
                    onDragOver={(e) => {
                        e.preventDefault();
                        setDragging(true);
                    }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={onDrop}
                    className={cn(
                        'border-border hover:border-primary/40 hover:bg-muted/40 focus-within:ring-ring flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed px-4 py-8 text-center transition-colors focus-within:ring-2',
                        dragging && 'border-primary bg-primary-soft',
                        (clientError || errors.file) && 'border-destructive/50',
                    )}
                >
                    <span className="bg-primary-soft text-primary flex size-10 items-center justify-center rounded-full">
                        <FileUp className="size-5" />
                    </span>
                    {form.data.file ? (
                        <span className="grid gap-0.5">
                            <span className="text-sm font-medium break-all">{form.data.file.name}</span>
                            <span className="text-muted-foreground text-xs">{size(form.data.file.size)}</span>
                        </span>
                    ) : (
                        <span className="grid gap-0.5">
                            <span className="text-sm font-medium">Drop the file here, or choose one</span>
                            <span className="text-muted-foreground text-xs">{limits.types.join(', ')} · up to 10 MB</span>
                        </span>
                    )}
                    <input
                        ref={input}
                        id="invoice-file"
                        type="file"
                        accept={ACCEPT}
                        className="sr-only"
                        onChange={(e) => choose(e.target.files?.[0])}
                        aria-invalid={Boolean(clientError || errors.file) || undefined}
                    />
                </label>
                {(clientError || errors.file) && <p className="text-destructive text-sm">{clientError ?? errors.file}</p>}

                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end">
                    {form.data.file && (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => {
                                form.setData('file', null);
                                if (input.current) {
                                    input.current.value = '';
                                }
                            }}
                        >
                            <X />
                            Remove file
                        </Button>
                    )}
                    <Button type="button" variant={canRead ? 'outline' : 'default'} disabled={form.processing} onClick={() => submit(true)}>
                        <PencilLine />
                        Enter by hand
                    </Button>
                    {canRead && (
                        <Button type="button" disabled={form.processing || !form.data.file} onClick={() => submit(false)}>
                            {form.processing ? <LoaderCircle className="animate-spin" /> : <Sparkles />}
                            Read invoice
                        </Button>
                    )}
                </div>
            </div>
        </SectionCard>
    );
}

import { FormSection } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Plus, ScanBarcode, Star, Trash2 } from 'lucide-react';
import { type BarcodeValue, type SectionProps } from './types';

/** The product's barcodes: one is the primary (printed on labels). Existing rows keep their id. */
export function BarcodeEditor({ data, setData, errors, disabled }: Pick<SectionProps, 'data' | 'setData' | 'errors'> & { disabled?: boolean }) {
    const rows = data.barcodes;
    const set = (next: BarcodeValue[]) => setData('barcodes', next);
    const update = (index: number, patch: Partial<BarcodeValue>) => set(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    const makePrimary = (index: number) => set(rows.map((row, i) => ({ ...row, is_primary: i === index })));
    const remove = (index: number) => {
        const next = rows.filter((_, i) => i !== index);
        set(next.some((row) => row.is_primary) || next.length === 0 ? next : next.map((row, i) => ({ ...row, is_primary: i === 0 })));
    };

    return (
        <FormSection title="Barcodes" description="Every barcode that should ring up this product. A pack barcode sells several at once.">
            {rows.length === 0 && (
                <div className="text-muted-foreground flex items-center gap-2 rounded-lg border border-dashed p-4 text-sm">
                    <ScanBarcode className="size-4" aria-hidden />
                    No barcode yet. Staff can still find it by name on the till.
                </div>
            )}
            {rows.map((row, index) => {
                const error = errors[`barcodes.${index}.barcode`] ?? errors[`barcodes.${index}.pack_qty`];

                return (
                    <div key={row.id ?? `new-${index}`} className="grid gap-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                aria-label={`Barcode ${index + 1}`}
                                className="min-w-0 flex-1 font-mono tabular-nums sm:max-w-72"
                                inputMode="numeric"
                                maxLength={50}
                                value={row.barcode}
                                disabled={disabled}
                                aria-invalid={!!error || undefined}
                                onChange={(e) => update(index, { barcode: e.target.value.trim() })}
                            />
                            <div className="relative w-28">
                                <Input
                                    aria-label={`Pack quantity for barcode ${index + 1}`}
                                    inputMode="numeric"
                                    className="pr-10 tabular-nums"
                                    value={row.pack_qty}
                                    disabled={disabled}
                                    onChange={(e) => update(index, { pack_qty: e.target.value })}
                                />
                                <span className="text-muted-foreground pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm" aria-hidden>
                                    each
                                </span>
                            </div>
                            <Button
                                type="button"
                                variant={row.is_primary ? 'secondary' : 'ghost'}
                                size="sm"
                                disabled={disabled || row.is_primary}
                                aria-pressed={row.is_primary}
                                onClick={() => makePrimary(index)}
                            >
                                <Star className={row.is_primary ? 'fill-current' : undefined} />
                                {row.is_primary ? 'Primary' : 'Make primary'}
                            </Button>
                            {!disabled && (
                                <Button type="button" variant="ghost" size="icon" aria-label={`Remove barcode ${row.barcode || index + 1}`} onClick={() => remove(index)}>
                                    <Trash2 />
                                </Button>
                            )}
                        </div>
                        {error && <p className="text-danger-foreground text-[13px]">{error}</p>}
                    </div>
                );
            })}
            {!disabled && rows.length < 20 && (
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => set([...rows, { id: null, barcode: '', pack_qty: '1', is_primary: rows.length === 0 }])}
                    >
                        <Plus />
                        Add barcode
                    </Button>
                </div>
            )}
            {errors.barcodes && <p className="text-danger-foreground text-[13px]">{errors.barcodes}</p>}
        </FormSection>
    );
}

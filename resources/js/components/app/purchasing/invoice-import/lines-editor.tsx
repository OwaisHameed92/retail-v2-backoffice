import { cost, money } from '@/components/app/purchasing/format';
import { EmptyState } from '@/components/shared/empty-state';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Link2, ListPlus, PackageSearch, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ProductPicker } from './product-picker';
import { type DraftLine, type InvoiceAnalysis, type InvoiceIssue, type InvoiceReviewProps } from './types';
import { taxText } from '@/lib/country';

const MATCHED_BY: Record<string, string> = {
    barcode: 'Barcode',
    supplierCode: 'Supplier code',
    sku: 'SKU',
    name: 'Name',
    user: 'Chosen by you',
};

export const blankLine = (): DraftLine => ({
    description: '',
    barcode: null,
    supplierCode: null,
    quantity: '1',
    packSize: 1,
    unitPrice: null,
    vatRate: null,
    lineNet: null,
    productId: null,
    pinned: false,
    matchedBy: null,
    confidence: 0,
    catalogueName: null,
});

interface LinesEditorProps {
    lines: DraftLine[];
    analysis: InvoiceAnalysis | null;
    issues: InvoiceIssue[];
    editable: boolean;
    errors: Record<string, string>;
    results: InvoiceReviewProps['results'];
    search: string | null;
    onChange: (lines: DraftLine[]) => void;
}

type Picked = { id: string; name: string; costPrice: string };

function Match({ line, product }: { line: DraftLine; product: Picked | null | undefined }) {
    if (!line.productId || !product) {
        return (
            <div className="grid gap-1">
                <StatusPill tone="warning">Not matched</StatusPill>
                {line.catalogueName && <span className="text-muted-foreground text-xs">In the master catalogue as {line.catalogueName}</span>}
            </div>
        );
    }
    const tone = line.confidence >= 95 ? 'success' : line.confidence >= 80 ? 'info' : 'warning';

    return (
        <div className="grid min-w-0 gap-1">
            <span className="truncate text-sm font-medium" title={product.name}>
                {product.name}
            </span>
            <span className="flex flex-wrap items-center gap-1.5">
                <StatusPill tone={tone}>
                    {MATCHED_BY[line.matchedBy ?? ''] ?? 'Matched'}
                    {line.matchedBy !== 'user' ? ` · ${line.confidence}%` : ''}
                </StatusPill>
                <span className="text-muted-foreground text-xs tabular-nums">Cost now {cost(product.costPrice)}</span>
            </span>
        </div>
    );
}

/** The invoice lines: what was read, editable, each with its product match, its sum and its checks. */
export function LinesEditor({ lines, analysis, issues, editable, errors, results, search, onChange }: LinesEditorProps) {
    const [picking, setPicking] = useState<number | null>(null);
    const [picked, setPicked] = useState<Record<string, Picked>>({});
    const set = (index: number, patch: Partial<DraftLine>) => onChange(lines.map((line, i) => (i === index ? { ...line, ...patch } : line)));
    const field = (index: number, key: keyof DraftLine) => errors[`lines.${index}.${key}`];
    // Figures by position are only right while the lines are the saved ones; products are found by id.
    const stale = (analysis?.lines.length ?? 0) !== lines.length;
    const known: Record<string, Picked> = { ...picked };
    analysis?.lines.forEach((l) => {
        if (l.product) {
            known[l.product.id] = l.product;
        }
    });

    if (lines.length === 0) {
        return (
            <EmptyState
                icon={ListPlus}
                size="sm"
                title="No lines yet"
                body={editable ? 'Add each product line as it is printed on the invoice.' : 'This invoice has no lines.'}
                action={
                    editable && (
                        <Button type="button" variant="outline" onClick={() => onChange([blankLine()])}>
                            <Plus />
                            Add a line
                        </Button>
                    )
                }
            />
        );
    }

    return (
        <div className="divide-border divide-y">
            {lines.map((line, i) => {
                const a = stale ? undefined : analysis?.lines[i];
                const lineIssues = stale ? [] : issues.filter((issue) => issue.line === i + 1 && issue.code !== 'unmatched');
                const input = (key: 'quantity' | 'unitPrice' | 'vatRate' | 'lineNet', label: string, className?: string) => (
                    <label className={cn('grid gap-1', className)}>
                        <span className="text-muted-foreground text-xs">{label}</span>
                        <Input
                            inputMode="decimal"
                            value={line[key] ?? ''}
                            disabled={!editable}
                            aria-invalid={Boolean(field(i, key)) || undefined}
                            onChange={(e) => set(i, { [key]: e.target.value === '' ? null : e.target.value })}
                            className="h-8 text-right tabular-nums"
                        />
                    </label>
                );

                return (
                    <div key={i} className="grid gap-3 px-4 py-4 sm:px-6">
                        <div className="flex items-start gap-3">
                            <span className="bg-muted text-muted-foreground mt-1 flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-medium tabular-nums">
                                {i + 1}
                            </span>
                            <div className="grid min-w-0 flex-1 gap-3 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                                <div className="grid gap-2">
                                    <Input
                                        value={line.description}
                                        disabled={!editable}
                                        placeholder="What the line is"
                                        aria-label={`Line ${i + 1} description`}
                                        aria-invalid={Boolean(field(i, 'description')) || undefined}
                                        onChange={(e) => set(i, { description: e.target.value })}
                                        className="h-8"
                                    />
                                    <div className="grid grid-cols-2 gap-2">
                                        <Input
                                            value={line.barcode ?? ''}
                                            disabled={!editable}
                                            placeholder="Barcode"
                                            aria-label={`Line ${i + 1} barcode`}
                                            onChange={(e) => set(i, { barcode: e.target.value || null, pinned: false })}
                                            className="h-8 font-mono text-xs"
                                        />
                                        <Input
                                            value={line.supplierCode ?? ''}
                                            disabled={!editable}
                                            placeholder="Supplier code"
                                            aria-label={`Line ${i + 1} supplier code`}
                                            onChange={(e) => set(i, { supplierCode: e.target.value || null, pinned: false })}
                                            className="h-8 font-mono text-xs"
                                        />
                                    </div>
                                </div>
                                <div className="flex items-start justify-between gap-2">
                                    <Match line={line} product={line.productId ? known[line.productId] : null} />
                                    {editable && (
                                        <div className="flex shrink-0 gap-1">
                                            <Button type="button" size="sm" variant="outline" onClick={() => setPicking(i)}>
                                                {line.productId ? <Link2 /> : <PackageSearch />}
                                                {line.productId ? 'Change' : 'Pick'}
                                            </Button>
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                className="size-8"
                                                aria-label={`Remove line ${i + 1}`}
                                                onClick={() => onChange(lines.filter((_, j) => j !== i))}
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:ml-9 lg:grid-cols-6">
                            {input('quantity', 'Quantity')}
                            <label className="grid gap-1">
                                <span className="text-muted-foreground text-xs">Items in each</span>
                                <Input
                                    inputMode="numeric"
                                    value={String(line.packSize)}
                                    disabled={!editable}
                                    aria-invalid={Boolean(field(i, 'packSize')) || undefined}
                                    onChange={(e) => set(i, { packSize: Math.max(1, Number.parseInt(e.target.value || '1', 10) || 1) })}
                                    className="h-8 text-right tabular-nums"
                                />
                            </label>
                            {input('unitPrice', taxText('Price ex VAT'))}
                            {input('vatRate', taxText('VAT %'))}
                            {input('lineNet', taxText('Line total ex VAT'))}
                            <div className="grid content-end gap-1 text-right">
                                <span className="text-muted-foreground text-xs">Cost per item</span>
                                <span className="text-sm font-medium tabular-nums">{a?.costPerItem ? cost(a.costPerItem) : '—'}</span>
                                {a?.reference && (
                                    <span className="text-muted-foreground text-xs tabular-nums">
                                        {analysis?.reference === 'delivery' ? 'Received' : 'Ordered'} {Number(a.reference.units)} at {cost(a.reference.unitCost)}
                                    </span>
                                )}
                            </div>
                        </div>

                        {(lineIssues.length > 0 || Object.keys(errors).some((k) => k.startsWith(`lines.${i}.`))) && (
                            <ul className="grid gap-1 lg:ml-9">
                                {Object.entries(errors)
                                    .filter(([k]) => k.startsWith(`lines.${i}.`))
                                    .map(([k, message]) => (
                                        <li key={k} className="text-destructive text-xs">
                                            {message}
                                        </li>
                                    ))}
                                {lineIssues.map((issue) => (
                                    <li
                                        key={issue.code}
                                        className={cn('text-xs', issue.level === 'info' ? 'text-muted-foreground' : 'text-warning-foreground font-medium')}
                                    >
                                        {issue.message.replace(/^Line \d+: /, '')}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {a?.calcNet && line.lineNet === null && (
                            <p className="text-muted-foreground text-xs lg:ml-9">Line total worked out: {money(a.calcNet)}</p>
                        )}
                    </div>
                );
            })}

            {editable && (
                <div className="px-4 py-3 sm:px-6">
                    <Button type="button" variant="outline" size="sm" onClick={() => onChange([...lines, blankLine()])}>
                        <Plus />
                        Add a line
                    </Button>
                </div>
            )}

            <ProductPicker
                open={picking !== null}
                onOpenChange={(open) => !open && setPicking(null)}
                description={picking !== null ? lines[picking]?.description || `line ${picking + 1}` : ''}
                results={results}
                search={search}
                onPick={(product) => {
                    if (product) {
                        setPicked((current) => ({ ...current, [product.id]: product }));
                    }
                    if (picking !== null) {
                        set(picking, { productId: product?.id ?? null, pinned: true, matchedBy: product ? 'user' : null, confidence: product ? 100 : 0 });
                    }
                    setPicking(null);
                }}
            />
        </div>
    );
}

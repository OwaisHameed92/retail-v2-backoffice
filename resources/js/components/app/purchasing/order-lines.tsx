import { MoneyInput, NumberField, OptionSelect } from '@/components/app/products/fields';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { taxName } from '@/lib/country';
import { cn } from '@/lib/utils';
import { ListPlus, PackagePlus, Plus, Search, Trash2 } from 'lucide-react';
import { cost, money, qty } from './format';
import { type CatalogueProduct, type OrderFormLine, type OrderFormProps } from './types';

export function toLine(p: CatalogueProduct, cases?: number): OrderFormLine {
    return {
        productId: p.productId,
        name: p.name,
        sku: p.sku,
        orderedCases: cases ?? Math.max(1, p.suggestedCases),
        caseQty: p.caseQty,
        looseUnits: 0,
        unitCost: p.unitCost,
        vatRateId: p.vatRateId,
        onHand: p.onHand,
    };
}

/** Units × cost, shown while typing (the server works out the order's totals when it is saved). */
function lineNet(line: OrderFormLine): number {
    return (line.orderedCases * line.caseQty + line.looseUnits) * Number(line.unitCost || 0);
}

const whole = (value: string) => Math.max(0, Math.floor(Number(value.replace(/[^\d]/g, '')) || 0));

/** The order's lines: cases × case size + loose units at a cost ex VAT, with the shop's stock beside each. */
export function LinesEditor({
    lines,
    vatRates,
    errors,
    onChange,
}: {
    lines: OrderFormLine[];
    vatRates: OrderFormProps['vatRates'];
    errors: Record<string, string>;
    onChange: (lines: OrderFormLine[]) => void;
}) {
    const set = (i: number, patch: Partial<OrderFormLine>) => onChange(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    const lineError = (i: number) => Object.entries(errors).find(([key]) => key.startsWith(`lines.${i}.`) || key === `lines.${i}`)?.[1];

    if (lines.length === 0) {
        return (
            <EmptyState
                icon={PackagePlus}
                title="No products yet"
                body="Add products from the supplier's list or search for any product."
                className="py-10"
            />
        );
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Product</TableHead>
                    <TableHead className="w-24">Cases</TableHead>
                    <TableHead className="w-24">Case size</TableHead>
                    <TableHead className="w-24">Loose</TableHead>
                    <TableHead className="w-32">Unit cost</TableHead>
                    <TableHead className="w-40">{taxName()}</TableHead>
                    <TableHead className="text-right">Net</TableHead>
                    <TableHead className="w-10">
                        <span className="sr-only">Remove</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {lines.map((line, i) => (
                    <TableRow key={line.productId} className="align-top">
                        <TableCell>
                            <div className="grid min-w-40 leading-5">
                                <span className="font-medium">{line.name}</span>
                                <span className="text-muted-foreground text-xs">
                                    {line.sku ? `${line.sku} · ` : ''}
                                    {line.onHand !== null ? `${qty(line.onHand)} in stock` : 'Stock not known'}
                                </span>
                                {lineError(i) && <span className="text-destructive text-xs">{lineError(i)}</span>}
                            </div>
                        </TableCell>
                        <TableCell>
                            <NumberField
                                id={`cases-${i}`}
                                inputMode="numeric"
                                value={String(line.orderedCases)}
                                onChange={(e) => set(i, { orderedCases: whole(e.target.value) })}
                                aria-label={`Cases of ${line.name}`}
                            />
                        </TableCell>
                        <TableCell>
                            <NumberField
                                id={`case-${i}`}
                                inputMode="numeric"
                                value={String(line.caseQty)}
                                onChange={(e) => set(i, { caseQty: Math.max(1, whole(e.target.value)) })}
                                aria-label={`Case size of ${line.name}`}
                            />
                        </TableCell>
                        <TableCell>
                            <NumberField
                                id={`loose-${i}`}
                                inputMode="numeric"
                                value={String(line.looseUnits)}
                                onChange={(e) => set(i, { looseUnits: whole(e.target.value) })}
                                aria-label={`Loose units of ${line.name}`}
                            />
                        </TableCell>
                        <TableCell>
                            <MoneyInput
                                id={`cost-${i}`}
                                places={4}
                                value={line.unitCost}
                                onChange={(e) => set(i, { unitCost: e.target.value })}
                                aria-label={`Unit cost of ${line.name}`}
                            />
                        </TableCell>
                        <TableCell>
                            <OptionSelect
                                id={`vat-${i}`}
                                value={line.vatRateId ?? ''}
                                onChange={(vatRateId) => set(i, { vatRateId })}
                                options={vatRates.map((v) => ({ value: v.id, label: `${v.name} (${qty(v.percentage)}%)` }))}
                            />
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            <div className="grid leading-5">
                                <span>{money(lineNet(line))}</span>
                                <span className="text-muted-foreground text-xs">{qty(line.orderedCases * line.caseQty + line.looseUnits)} units</span>
                            </div>
                        </TableCell>
                        <TableCell>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                onClick={() => onChange(lines.filter((_, j) => j !== i))}
                                aria-label={`Remove ${line.name}`}
                            >
                                <Trash2 />
                            </Button>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/** Products to add: the supplier's list with a suggested number of cases (from the shop's stock), or a search. */
export function CatalogueCard({
    title,
    description,
    products,
    chosen,
    onAdd,
    search,
    onSearch,
    emptyTitle,
    emptyBody,
}: {
    title: string;
    description: string;
    products: CatalogueProduct[];
    chosen: Set<string>;
    onAdd: (products: CatalogueProduct[]) => void;
    search?: string;
    onSearch?: (value: string) => void;
    emptyTitle: string;
    emptyBody: string;
}) {
    const suggested = products.filter((p) => p.suggestedCases > 0 && !chosen.has(p.productId));

    return (
        <SectionCard
            title={title}
            description={description}
            flush
            actions={
                suggested.length > 0 && (
                    <Button type="button" variant="outline" size="sm" onClick={() => onAdd(suggested)}>
                        <ListPlus />
                        Add {suggested.length} suggested
                    </Button>
                )
            }
        >
            {onSearch && (
                <div className="relative border-b px-4 py-3">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-7 size-4 -translate-y-1/2" />
                    <Input
                        value={search ?? ''}
                        onChange={(e) => onSearch(e.target.value)}
                        placeholder="Search by name, SKU or barcode"
                        className="pl-9"
                        aria-label="Search products"
                    />
                </div>
            )}
            {products.length === 0 ? (
                <EmptyState icon={Search} title={emptyTitle} body={emptyBody} className="py-8" />
            ) : (
                <ul className="max-h-96 divide-y overflow-y-auto">
                    {products.map((p) => (
                        <li key={p.productId} className="flex items-center justify-between gap-3 px-4 py-2.5">
                            <div className="grid min-w-0 leading-5">
                                <span className="truncate text-sm font-medium">{p.name}</span>
                                <span className="text-muted-foreground truncate text-xs">
                                    {[
                                        p.supplierSku ?? p.sku,
                                        `case of ${p.caseQty}`,
                                        cost(p.unitCost),
                                        p.onHand !== null ? `${qty(p.onHand)} in stock` : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                {p.suggestedCases > 0 && <StatusPill tone="warning">Suggest {p.suggestedCases}</StatusPill>}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={chosen.has(p.productId)}
                                    onClick={() => onAdd([p])}
                                    className={cn(chosen.has(p.productId) && 'text-muted-foreground')}
                                >
                                    <Plus />
                                    {chosen.has(p.productId) ? 'Added' : 'Add'}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}

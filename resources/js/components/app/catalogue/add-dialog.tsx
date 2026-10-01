import { formatMoney, MoneyInput } from '@/components/app/products/fields';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { LoaderCircle, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DepartmentMapping, mappingPayload, PriceRuleFields } from './pricing-fields';
import { previewPrice, type CatalogueRow, type PriceRuleValues, type YourDepartment } from './types';

interface Props {
    rows: CatalogueRow[];
    yours: YourDepartment[];
    initialRule: PriceRuleValues;
    onRemove: (barcode: string) => void;
    onClose: () => void;
    onAdded: () => void;
}

type Prices = Record<string, { sell: string; cost: string }>;

/** Review the picked products: price rule, prices and costs per product, department mapping; then add them all. */
export function AddDialog({ rows, yours, initialRule, onRemove, onClose, onAdded }: Props) {
    const [rule, setRule] = useState<PriceRuleValues>(initialRule);
    const [prices, setPrices] = useState<Prices>({});
    const [mapping, setMapping] = useState<Record<string, string>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const departments = useMemo(() => {
        const counts = new Map<string, number>();
        rows.forEach((row) => counts.set(row.department ?? 'General', (counts.get(row.department ?? 'General') ?? 0) + 1));

        return [...counts.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([value, count]) => ({ value, label: value, count }));
    }, [rows]);

    const priceOf = (row: CatalogueRow) => previewPrice(rule, prices[row.barcode]?.sell ?? '', prices[row.barcode]?.cost ?? '', row.rrp, row.vatRate);
    const unpriced = rows.filter((row) => priceOf(row) === null).length;
    const setPrice = (barcode: string, key: 'sell' | 'cost', value: string) =>
        setPrices((current) => ({ ...current, [barcode]: { sell: current[barcode]?.sell ?? '', cost: current[barcode]?.cost ?? '', [key]: value } }));

    const submit = () =>
        router.post(
            route('app.products.catalogue.add'),
            {
                items: rows.map((row) => ({
                    barcode: row.barcode,
                    sell_price: prices[row.barcode]?.sell || null,
                    cost_price: prices[row.barcode]?.cost || null,
                })),
                ...rule,
                departments: mappingPayload(mapping),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errs) => setErrors(errs),
                onSuccess: onAdded,
            },
        );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Add {rows.length === 1 ? '1 product' : `${rows.length} products`} to your catalogue</DialogTitle>
                    <DialogDescription>Check the prices and where they file. Every shop&apos;s tills get them at their next sync.</DialogDescription>
                </DialogHeader>

                <section className="grid gap-3">
                    <h3 className="text-sm font-semibold">Pricing</h3>
                    <PriceRuleFields value={rule} onChange={setRule} errors={errors} />
                </section>

                <section className="grid gap-2">
                    <h3 className="text-sm font-semibold">Products</h3>
                    <div className="max-h-80 overflow-y-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-subtle text-muted-foreground sticky top-0 text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Product</th>
                                    <th className="w-28 px-3 py-2 font-medium">Cost (ex VAT)</th>
                                    <th className="w-28 px-3 py-2 font-medium">Sell price</th>
                                    <th className="w-10 px-2 py-2">
                                        <span className="sr-only">Remove</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.map((row) => {
                                    const price = priceOf(row);

                                    return (
                                        <tr key={row.barcode}>
                                            <td className="px-3 py-2">
                                                <p className="font-medium">{row.name}</p>
                                                <p className="text-muted-foreground font-mono text-xs tabular-nums">
                                                    {row.barcode} · RRP {row.rrp ? formatMoney(row.rrp) : 'none'}
                                                </p>
                                            </td>
                                            <td className="px-3 py-2">
                                                <MoneyInput
                                                    id={`cost-${row.barcode}`}
                                                    aria-label={`Cost of ${row.name}`}
                                                    places={4}
                                                    value={prices[row.barcode]?.cost ?? ''}
                                                    onChange={(e) => setPrice(row.barcode, 'cost', e.target.value)}
                                                />
                                            </td>
                                            <td className="px-3 py-2">
                                                <MoneyInput
                                                    id={`sell-${row.barcode}`}
                                                    aria-label={`Sell price of ${row.name}`}
                                                    placeholder={price ?? 'Needed'}
                                                    invalid={price === null}
                                                    value={prices[row.barcode]?.sell ?? ''}
                                                    onChange={(e) => setPrice(row.barcode, 'sell', e.target.value)}
                                                />
                                            </td>
                                            <td className="px-2 py-2">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Remove ${row.name}`}
                                                    onClick={() => onRemove(row.barcode)}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    {unpriced > 0 && (
                        <p className="text-warning-foreground text-sm">
                            {unpriced === 1 ? '1 product has' : `${unpriced} products have`} no RRP: type a sell price or a cost, or it is not added.
                        </p>
                    )}
                    {(errors.items || errors['items.0.barcode']) && (
                        <p className="text-danger-foreground text-[13px]">{errors.items ?? errors['items.0.barcode']}</p>
                    )}
                </section>

                <section className="grid gap-3">
                    <h3 className="text-sm font-semibold">Departments</h3>
                    <p className="text-muted-foreground text-sm">
                        Where each catalogue department files in your catalogue. Categories are matched by name or created.
                    </p>
                    <DepartmentMapping departments={departments} yours={yours} value={mapping} onChange={setMapping} error={errors.departments} />
                </section>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="button" onClick={submit} disabled={processing || rows.length === 0}>
                        {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                        Add {rows.length === 1 ? '1 product' : `${rows.length} products`}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

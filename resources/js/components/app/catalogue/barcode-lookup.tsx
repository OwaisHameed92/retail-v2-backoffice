import { formatMoney } from '@/components/app/products/fields';
import { type ProductValues, type SetValue } from '@/components/app/products/types';
import { SectionCard } from '@/components/shared/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { taxName } from '@/lib/country';
import { sendJson } from '@/lib/http';
import { Link } from '@inertiajs/react';
import { BookOpenCheck, CircleAlert, LoaderCircle, ScanBarcode } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { type LookupResult } from './types';

const LOOKUP_LENGTHS = [8, 12, 13, 14];

type Product = NonNullable<LookupResult['product']>;

/** The product form's values a catalogue match fills in; only empty fields (and a zero price) are changed. */
function prefill(data: ProductValues, setData: SetValue, product: Product, barcode: string) {
    const empty = (value: string) => value.trim() === '';
    if (empty(data.name)) setData('name', product.name);
    if (empty(data.brand) && product.brand) setData('brand', product.brand);
    if (empty(data.department_id) && product.departmentId) {
        setData('department_id', product.departmentId);
        if (product.categoryId) setData('category_id', product.categoryId);
    }
    if (empty(data.vat_rate_id) && product.vatRateId) setData('vat_rate_id', product.vatRateId);
    if ((empty(data.sell_price) || Number(data.sell_price) === 0) && product.rrp) setData('sell_price', product.rrp);
    if (data.age_rule === 'none' && product.ageRule !== 'none') setData('age_rule', product.ageRule);
    if (product.ageRule === 'tobaccoGenerational') setData('is_tobacco', true);
    if (empty(data.volume_ml) && product.volumeMl) setData('volume_ml', product.volumeMl);
    if (empty(data.net_mass_kg) && product.netMassKg) setData('net_mass_kg', product.netMassKg);
    addBarcode(data, setData, barcode);
}

/** Puts the looked-up barcode on the product (primary when it has none yet). */
function addBarcode(data: ProductValues, setData: SetValue, barcode: string) {
    if (data.barcodes.some((row) => row.barcode === barcode)) {
        return;
    }
    const kept = data.barcodes.filter((row) => row.barcode !== '');
    setData('barcodes', [...kept, { id: null, barcode, pack_qty: '1', is_primary: !kept.some((row) => row.is_primary) }]);
}

/** New product: scan or type the barcode first; a match in the SSPOS catalogue fills in name, size, VAT and department. */
export function BarcodeLookup({ data, setData }: { data: ProductValues; setData: SetValue }) {
    const [barcode, setBarcode] = useState('');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<(LookupResult & { barcode: string }) | null>(null);
    const [filled, setFilled] = useState(false);
    const latest = useRef({ data, setData });
    latest.current = { data, setData };

    useEffect(() => {
        const code = barcode.replace(/\s+/g, '');
        if (!/^\d+$/.test(code) || !LOOKUP_LENGTHS.includes(code.length)) {
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            const reply = await sendJson<LookupResult>(
                'GET',
                route('app.products.catalogue.lookup', { barcode: code }),
                undefined,
                controller.signal,
            );
            setLoading(false);
            if (!reply.ok || !reply.data) {
                return;
            }
            setResult({ ...reply.data, barcode: code });
            const { data: current, setData: set } = latest.current;
            setFilled(false);
            if (reply.data.existing) {
                return;
            }
            if (reply.data.product && current.name.trim() === '') {
                prefill(current, set, reply.data.product, code);
                setFilled(true);
            } else {
                addBarcode(current, set, code);
            }
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [barcode]);

    const product = result?.product;

    return (
        <SectionCard
            title="Start with the barcode"
            description="Scan or type it: if the SSPOS catalogue knows the product, its details are filled in for you."
        >
            <div className="grid gap-3">
                <div className="relative sm:max-w-80">
                    <ScanBarcode className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
                    <Input
                        aria-label="Barcode to look up"
                        autoFocus
                        inputMode="numeric"
                        maxLength={20}
                        className="pl-9 font-mono tabular-nums"
                        placeholder="e.g. 5000157024671"
                        value={barcode}
                        onChange={(e) => {
                            setBarcode(e.target.value.trim());
                            setResult(null);
                        }}
                        onKeyDown={(e) => e.key === 'Enter' && e.preventDefault()}
                    />
                    {loading && (
                        <LoaderCircle className="text-muted-foreground absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin" aria-hidden />
                    )}
                </div>

                <div aria-live="polite">
                    {result?.existing && (
                        <p className="text-warning-foreground flex items-start gap-2 text-sm">
                            <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                            <span>
                                You already sell this barcode as{' '}
                                <Link href={route('app.products.show', result.existing.id)} className="font-medium underline underline-offset-4">
                                    {result.existing.name}
                                </Link>
                                .
                            </span>
                        </p>
                    )}
                    {result && !result.existing && product && (
                        <div className="bg-subtle flex flex-wrap items-center gap-3 rounded-lg border p-3">
                            <BookOpenCheck className="text-primary size-5 shrink-0" aria-hidden />
                            <div className="min-w-0 flex-1 text-sm">
                                <p className="font-medium">{product.name}</p>
                                <p className="text-muted-foreground">
                                    {[
                                        product.size,
                                        product.department,
                                        product.vatRate ? `${taxName()} ${product.vatRate}` : null,
                                        product.rrp ? `RRP ${formatMoney(product.rrp)}` : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            </div>
                            {filled ? (
                                <span className="text-success-foreground text-sm font-medium">Filled in below. Check and save.</span>
                            ) : (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        prefill(data, setData, product, result.barcode);
                                        setFilled(true);
                                    }}
                                >
                                    Fill in empty fields
                                </Button>
                            )}
                        </div>
                    )}
                    {result && !result.existing && !product && (
                        <p className="text-muted-foreground text-sm">
                            Not in the SSPOS catalogue yet. Fill in the details below; the barcode is added for you.
                        </p>
                    )}
                </div>
            </div>
        </SectionCard>
    );
}

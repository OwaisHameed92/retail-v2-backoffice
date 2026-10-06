import { OptionSelect } from '@/components/app/products/fields';
import { FormField } from '@/components/shared/form-section';
import { PageTabs } from '@/components/shared/page-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatMoneyAsGiven } from '@/lib/country';
import { sendJson } from '@/lib/http';
import { router } from '@inertiajs/react';
import { LoaderCircle, ScanBarcode, Search } from 'lucide-react';
import { type FormEventHandler, useEffect, useState } from 'react';
import { type Option } from './types';

interface Found {
    id: string;
    name: string;
    sku: string | null;
    barcode: string | null;
    price: string;
    waiting: boolean;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    shopId: string;
    shopName: string;
    departments: Option[];
    suppliers: Option[];
}

type Mode = 'products' | 'department' | 'supplier';

/** Add labels by hand: products found by name, code or a scanned barcode; a whole department; a supplier's products. */
export function AddLabelsDialog({ open, onOpenChange, shopId, shopName, departments, suppliers }: Props) {
    const [mode, setMode] = useState<Mode>('products');
    const [search, setSearch] = useState('');
    const [found, setFound] = useState<Found[]>([]);
    const [searching, setSearching] = useState(false);
    const [chosen, setChosen] = useState<Record<string, Found>>({});
    const [department, setDepartment] = useState('');
    const [supplier, setSupplier] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            setMode('products');
            setSearch('');
            setFound([]);
            setChosen({});
            setDepartment('');
            setSupplier('');
            setErrors({});
        }
    }, [open]);

    useEffect(() => {
        if (!open) {
            return;
        }
        if (search.trim().length < 2) {
            setFound([]);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(() => {
            setSearching(true);
            sendJson<{ products: Found[] }>(
                'GET',
                route('app.labels.products', { branch_id: shopId, q: search.trim() }),
                undefined,
                controller.signal,
            )
                .then((result) => {
                    setFound(result.data?.products ?? []);
                    // A scanned barcode that finds exactly one product is picked straight away.
                    const only = result.data?.products.length === 1 ? result.data.products[0] : null;
                    if (only && only.barcode === search.trim()) {
                        setChosen((current) => ({ ...current, [only.id]: only }));
                        setSearch('');
                    }
                })
                .catch(() => undefined)
                .finally(() => setSearching(false));
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [open, search, shopId]);

    const toggle = (product: Found) =>
        setChosen((current) => {
            const next = { ...current };
            if (next[product.id]) {
                delete next[product.id];
            } else {
                next[product.id] = product;
            }
            return next;
        });

    const count = Object.keys(chosen).length;
    const ready = mode === 'products' ? count > 0 : mode === 'department' ? department !== '' : supplier !== '';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const data =
            mode === 'products'
                ? { branch_id: shopId, product_ids: Object.keys(chosen) }
                : mode === 'department'
                  ? { branch_id: shopId, department_id: department }
                  : { branch_id: shopId, supplier_id: supplier };
        router.post(route('app.labels.queue'), data, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (errs) => setErrors(errs as Record<string, string>),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} noValidate className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Add labels</DialogTitle>
                        <DialogDescription>Labels for {shopName}. A product already waiting is not added twice.</DialogDescription>
                    </DialogHeader>

                    <PageTabs
                        label="Add labels by"
                        value={mode}
                        onChange={(value) => {
                            setMode(value as Mode);
                            setErrors({});
                        }}
                        tabs={[
                            { label: 'Products', value: 'products', count: count || undefined },
                            { label: 'Department', value: 'department' },
                            { label: 'Supplier', value: 'supplier' },
                        ]}
                    />

                    {mode === 'products' && (
                        <div className="grid gap-3">
                            <FormField
                                id="label-search"
                                label="Find products"
                                help="Type a name or code, or scan a barcode."
                                error={errors.product_ids}
                            >
                                <div className="relative">
                                    <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" aria-hidden />
                                    <Input
                                        id="label-search"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        className="pl-9"
                                        placeholder="Name, code or barcode"
                                        autoComplete="off"
                                        autoFocus
                                    />
                                    {searching && (
                                        <LoaderCircle
                                            className="text-muted-foreground absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin"
                                            aria-hidden
                                        />
                                    )}
                                </div>
                            </FormField>

                            {search.trim().length >= 2 && !searching && found.length === 0 && (
                                <p className="text-muted-foreground text-sm">No products on sale match "{search.trim()}".</p>
                            )}

                            {found.length > 0 && (
                                <ul className="max-h-64 divide-y overflow-y-auto rounded-md border" aria-label="Products found">
                                    {found.map((product) => (
                                        <li key={product.id}>
                                            <label className="hover:bg-muted/60 flex cursor-pointer items-center gap-3 px-3 py-2">
                                                <Checkbox checked={Boolean(chosen[product.id])} onCheckedChange={() => toggle(product)} />
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm font-medium">{product.name}</span>
                                                    <span className="text-muted-foreground block truncate font-mono text-xs">
                                                        {product.barcode ?? product.sku ?? '—'}
                                                    </span>
                                                </span>
                                                {product.waiting && <Badge variant="neutral">Waiting</Badge>}
                                                <span className="text-sm tabular-nums">{formatMoneyAsGiven(product.price)}</span>
                                            </label>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {count > 0 && (
                                <div className="flex flex-wrap gap-1.5" aria-label="Chosen products">
                                    {Object.values(chosen).map((product) => (
                                        <Badge key={product.id} variant="outline" className="gap-1">
                                            {product.name}
                                            <button
                                                type="button"
                                                className="hover:text-foreground ml-0.5"
                                                onClick={() => toggle(product)}
                                                aria-label={`Remove ${product.name}`}
                                            >
                                                ×
                                            </button>
                                        </Badge>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {mode === 'department' && (
                        <FormField id="department_id" label="Department" help="Every product on sale in it." error={errors.department_id}>
                            <OptionSelect
                                id="department_id"
                                value={department}
                                options={departments}
                                onChange={setDepartment}
                                invalid={Boolean(errors.department_id)}
                            />
                        </FormField>
                    )}

                    {mode === 'supplier' && (
                        <FormField
                            id="supplier_id"
                            label="Supplier"
                            help={
                                suppliers.length === 0
                                    ? 'No suppliers yet. Add them under Suppliers.'
                                    : 'Every product on sale that this supplier supplies.'
                            }
                            error={errors.supplier_id}
                        >
                            <OptionSelect
                                id="supplier_id"
                                value={supplier}
                                options={suppliers}
                                onChange={setSupplier}
                                invalid={Boolean(errors.supplier_id)}
                                disabled={suppliers.length === 0}
                            />
                        </FormField>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={!ready || processing}>
                            {processing ? (
                                <LoaderCircle className="size-4 animate-spin" aria-hidden />
                            ) : (
                                <ScanBarcode className="size-4" aria-hidden />
                            )}
                            {mode === 'products' && count > 0 ? `Add ${count} ${count === 1 ? 'label' : 'labels'}` : 'Add labels'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

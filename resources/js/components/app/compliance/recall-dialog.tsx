import { OptionSelect } from '@/components/app/products/fields';
import { FormField, FormGrid } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import { LoaderCircle, Search, X } from 'lucide-react';
import { type FormEventHandler, useEffect, useRef, useState } from 'react';
import { type Option, type RecallDetail } from './types';

type RecallValues = {
    reference: string;
    product_id: string;
    product_name: string;
    batch_code: string;
    expiry_from: string;
    expiry_to: string;
    source: string;
    reason: string;
    supplier_id: string;
};

const SOURCES: Option[] = [
    { value: 'Food Standards Agency', label: 'Food Standards Agency' },
    { value: 'Manufacturer', label: 'Manufacturer' },
    { value: 'Supplier', label: 'Supplier' },
    { value: 'Trading Standards', label: 'Trading Standards' },
    { value: 'Head office', label: 'Head office' },
];

function initial(recall: RecallDetail | null): RecallValues {
    return {
        reference: recall?.reference ?? '',
        product_id: recall?.productId ?? '',
        product_name: recall?.product ?? '',
        batch_code: recall?.batchCode ?? '',
        expiry_from: recall?.expiryFrom ?? '',
        expiry_to: recall?.expiryTo ?? '',
        source: recall?.source ?? '',
        reason: recall?.reason ?? '',
        supplier_id: recall?.supplierId ?? '',
    };
}

interface Props {
    recall: RecallDetail | null;
    suppliers: Option[];
    productResults?: Option[];
    onClose: () => void;
}

/** Raise or edit a product recall. Every till receives it at its next sync (ProductRecall is portal-owned). */
export function RecallDialog({ recall, suppliers, productResults = [], onClose }: Props) {
    const { data, setData, post, put, processing, errors } = useForm<RecallValues>(initial(recall));
    const [query, setQuery] = useState('');
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const sources = data.source && !SOURCES.some((s) => s.value === data.source) ? [...SOURCES, { value: data.source, label: data.source }] : SOURCES;

    useEffect(() => () => void (timer.current && clearTimeout(timer.current)), []);

    const search = (value: string) => {
        setQuery(value);
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => router.reload({ only: ['productResults'], data: { q: value } }), 300);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: onClose };
        if (recall) put(route('app.compliance.recalls.update', recall.id), done);
        else post(route('app.compliance.recalls.store'), done);
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{recall ? `Edit recall ${recall.reference ?? ''}` : 'Raise a product recall'}</DialogTitle>
                        <DialogDescription>Every shop's till gets this at its next sync, so staff can find and withdraw the stock.</DialogDescription>
                    </DialogHeader>

                    <FormField
                        id="recall-product"
                        label="Product"
                        error={errors.product_id ?? errors.product_name}
                        help="Pick it from your catalogue, or type the name if you do not stock it by that name."
                    >
                        <div className="grid gap-2">
                            {data.product_id ? (
                                <div className="border-input flex h-9 items-center gap-2 rounded-md border px-3 text-sm">
                                    <span className="min-w-0 flex-1 truncate font-medium">{data.product_name || 'Catalogue product'}</span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-7"
                                        aria-label="Clear the product"
                                        onClick={() => setData({ ...data, product_id: '', product_name: '' })}
                                    >
                                        <X />
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <div className="relative">
                                        <Search className="text-muted-foreground absolute top-2.5 left-3 size-4" aria-hidden />
                                        <Input
                                            id="recall-product"
                                            className="pl-9"
                                            placeholder="Search your catalogue by name or SKU"
                                            value={query}
                                            onChange={(e) => search(e.target.value)}
                                        />
                                    </div>
                                    {query.length >= 2 && productResults.length > 0 && (
                                        <ul
                                            className="border-border max-h-44 overflow-y-auto rounded-md border text-sm"
                                            aria-label="Matching products"
                                        >
                                            {productResults.map((p) => (
                                                <li key={p.value}>
                                                    <button
                                                        type="button"
                                                        className="hover:bg-muted w-full px-3 py-2 text-left"
                                                        onClick={() =>
                                                            setData({ ...data, product_id: p.value, product_name: p.label.split(' · ')[0] })
                                                        }
                                                    >
                                                        {p.label}
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    <Input
                                        aria-label="Product name"
                                        placeholder="…or type the product name"
                                        maxLength={255}
                                        value={data.product_name}
                                        onChange={(e) => setData('product_name', e.target.value)}
                                    />
                                </>
                            )}
                        </div>
                    </FormField>

                    <FormGrid>
                        <FormField id="recall-batch" label="Batch or lot code" optional error={errors.batch_code}>
                            <Input
                                id="recall-batch"
                                maxLength={100}
                                value={data.batch_code}
                                onChange={(e) => setData('batch_code', e.target.value)}
                            />
                        </FormField>
                        <FormField id="recall-reference" label="Reference" optional help="Left blank, one is made up." error={errors.reference}>
                            <Input
                                id="recall-reference"
                                maxLength={40}
                                value={data.reference}
                                onChange={(e) => setData('reference', e.target.value)}
                            />
                        </FormField>
                        <FormField id="recall-from" label="Best before from" optional error={errors.expiry_from}>
                            <Input id="recall-from" type="date" value={data.expiry_from} onChange={(e) => setData('expiry_from', e.target.value)} />
                        </FormField>
                        <FormField id="recall-to" label="Best before to" optional error={errors.expiry_to}>
                            <Input
                                id="recall-to"
                                type="date"
                                value={data.expiry_to}
                                min={data.expiry_from || undefined}
                                onChange={(e) => setData('expiry_to', e.target.value)}
                            />
                        </FormField>
                        <FormField id="recall-source" label="Notice from" optional error={errors.source}>
                            <OptionSelect
                                id="recall-source"
                                value={data.source}
                                options={sources}
                                none="Not given"
                                onChange={(v) => setData('source', v)}
                            />
                        </FormField>
                        <FormField id="recall-supplier" label="Supplier" optional error={errors.supplier_id}>
                            <OptionSelect
                                id="recall-supplier"
                                value={data.supplier_id}
                                options={suppliers}
                                none="None"
                                onChange={(v) => setData('supplier_id', v)}
                            />
                        </FormField>
                    </FormGrid>

                    <FormField id="recall-reason" label="Why it is recalled" error={errors.reason}>
                        <Textarea
                            id="recall-reason"
                            required
                            rows={3}
                            maxLength={1000}
                            value={data.reason}
                            aria-invalid={!!errors.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                        />
                    </FormField>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                            {recall ? 'Save recall' : 'Raise recall'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

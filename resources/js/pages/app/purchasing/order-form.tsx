import { OptionSelect } from '@/components/app/products/fields';
import { CatalogueCard, LinesEditor, toLine } from '@/components/app/purchasing/order-lines';
import { type CatalogueProduct, type OrderFormLine, type OrderFormProps } from '@/components/app/purchasing/types';
import { FormCard, FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Info, Save, Send } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

type FormData = {
    shopId: string;
    supplierId: string;
    status: 'draft' | 'sent';
    expectedDate: string;
    notes: string;
    lines: OrderFormLine[];
};

/** Draft or change a head-office order for one shop (module 5.2, contract §10.6). */
export default function HeadOfficeOrderForm({ order, shopId, supplierId, shops, suppliers, vatRates, suggestions, search, results }: OrderFormProps) {
    const editing = order !== null;
    const form = useForm<FormData>({
        shopId: shopId ?? '',
        supplierId: supplierId ?? order?.supplierId ?? '',
        status: 'draft',
        expectedDate: order?.expectedDate ?? '',
        notes: order?.notes ?? '',
        lines: order?.lines ?? [],
    });
    const [query, setQuery] = useState(search ?? '');
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const errors = form.errors as Record<string, string>;
    const chosen = useMemo(() => new Set(form.data.lines.map((l) => l.productId)), [form.data.lines]);
    const shop = shops.find((s) => s.id === form.data.shopId);
    const supplier = suppliers.find((s) => s.id === form.data.supplierId);

    // Reload the supplier's products (and the shop's stock) when the shop or supplier changes.
    const reload = (params: Record<string, string>, only: string[]) =>
        router.get(
            window.location.pathname,
            { shop: form.data.shopId, supplier: form.data.supplierId, q: query, ...params },
            { only, preserveState: true, preserveScroll: true, replace: true },
        );

    useEffect(() => () => clearTimeout(timer.current), []);

    const add = (products: CatalogueProduct[]) =>
        form.setData('lines', [...form.data.lines, ...products.filter((p) => !chosen.has(p.productId)).map((p) => toLine(p))]);
    const submit = (status: 'draft' | 'sent') => {
        form.transform((data) => ({ ...data, status }));
        const options = { preserveScroll: true };
        if (editing) {
            form.put(route('app.purchasing.orders.update', order.id), options);
        } else {
            form.post(route('app.purchasing.orders.store'), options);
        }
    };

    const title = editing ? `Edit ${order.reference}` : 'New head-office order';

    return (
        <AppLayout>
            <Head title={title} />

            <PageHeader
                title={title}
                back={
                    editing
                        ? { href: route('app.purchasing.orders.show', order.id), label: order.reference }
                        : { href: route('app.purchasing.index', 'orders'), label: 'Orders' }
                }
                description="Order stock for one shop. The shop's till gets the order at its next sync and books the delivery in against it."
            />

            {(errors.order || errors.shopId) && (
                <Alert variant="destructive">
                    <Info />
                    <AlertDescription>{errors.order ?? errors.shopId}</AlertDescription>
                </Alert>
            )}

            <form
                className="grid gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit(order?.status === 'sent' ? 'sent' : 'draft');
                }}
            >
                <FormCard>
                    <FormSection
                        title="Order"
                        description="Who it is for and who supplies it. Once the shop sends, cancels or receives it, the order is the shop's."
                    >
                        <FormGrid>
                            <FormField
                                id="shopId"
                                label="Shop"
                                error={errors.shopId}
                                help={editing ? 'An order stays with the shop it was drafted for.' : undefined}
                            >
                                <OptionSelect
                                    id="shopId"
                                    value={form.data.shopId}
                                    disabled={editing}
                                    invalid={Boolean(errors.shopId)}
                                    onChange={(value) => {
                                        form.setData('shopId', value);
                                        reload({ shop: value }, ['shopId', 'suggestions', 'results']);
                                    }}
                                    options={shops.map((s) => ({ value: s.id, label: `${s.name} (${s.code})` }))}
                                    placeholder="Choose a shop"
                                />
                            </FormField>
                            <FormField id="supplierId" label="Supplier" error={errors.supplierId}>
                                <OptionSelect
                                    id="supplierId"
                                    value={form.data.supplierId}
                                    invalid={Boolean(errors.supplierId)}
                                    onChange={(value) => {
                                        form.setData('supplierId', value);
                                        reload({ supplier: value }, ['supplierId', 'suggestions', 'results']);
                                    }}
                                    options={suppliers.map((s) => ({ value: s.id, label: s.name }))}
                                    placeholder="Choose a supplier"
                                />
                            </FormField>
                            <FormField
                                id="expectedDate"
                                label="Expected delivery"
                                optional
                                error={errors.expectedDate}
                                help={supplier?.leadDays ? `${supplier.name} usually delivers in ${supplier.leadDays} days.` : undefined}
                            >
                                <Input
                                    id="expectedDate"
                                    type="date"
                                    value={form.data.expectedDate}
                                    onChange={(e) => form.setData('expectedDate', e.target.value)}
                                    aria-invalid={Boolean(errors.expectedDate) || undefined}
                                />
                            </FormField>
                            <FormField id="notes" label="Notes for the shop" optional error={errors.notes}>
                                <Textarea
                                    id="notes"
                                    rows={2}
                                    maxLength={1000}
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                />
                            </FormField>
                        </FormGrid>
                    </FormSection>
                </FormCard>

                <SectionCard
                    title="Lines"
                    description={
                        errors.lines ??
                        "Cases × case size + loose units, at the cost ex VAT. Suggestions come from the shop's stock and reorder levels."
                    }
                    flush
                >
                    <LinesEditor lines={form.data.lines} vatRates={vatRates} errors={errors} onChange={(lines) => form.setData('lines', lines)} />
                </SectionCard>

                <div className="grid gap-4 lg:grid-cols-2 lg:items-start">
                    <CatalogueCard
                        title={supplier ? `${supplier.name} products` : "Supplier's products"}
                        description={
                            shop ? `With ${shop.name}'s stock and a suggested number of cases.` : 'Choose a shop to see its stock and suggestions.'
                        }
                        products={suggestions}
                        chosen={chosen}
                        onAdd={add}
                        emptyTitle={supplier ? 'No products linked to this supplier' : 'Choose a supplier'}
                        emptyBody={
                            supplier
                                ? 'Link products to the supplier on the product page, or search for any product.'
                                : 'Its products and suggested quantities appear here.'
                        }
                    />
                    <CatalogueCard
                        title="Find a product"
                        description="Any product in your catalogue."
                        products={results}
                        chosen={chosen}
                        onAdd={add}
                        search={query}
                        onSearch={(value) => {
                            setQuery(value);
                            clearTimeout(timer.current);
                            timer.current = setTimeout(() => reload({ q: value }, ['results', 'search']), 300);
                        }}
                        emptyTitle={query ? 'No products found' : 'Search the catalogue'}
                        emptyBody={query ? 'Try another name, SKU or barcode.' : 'Type a name, SKU or barcode.'}
                    />
                </div>

                <StickyFormBar
                    message={
                        form.data.lines.length === 0
                            ? 'Add at least one product.'
                            : `${form.data.lines.length} ${form.data.lines.length === 1 ? 'line' : 'lines'} for ${shop?.name ?? 'the shop'}.`
                    }
                >
                    <Button variant="outline" asChild>
                        <Link href={editing ? route('app.purchasing.orders.show', order.id) : route('app.purchasing.index', 'orders')}>Cancel</Link>
                    </Button>
                    {order?.status === 'sent' ? (
                        <Button type="button" disabled={form.processing} onClick={() => submit('sent')}>
                            <Save />
                            Save changes
                        </Button>
                    ) : (
                        <>
                            <Button type="submit" variant="outline" disabled={form.processing}>
                                <Save />
                                Save draft
                            </Button>
                            <Button type="button" disabled={form.processing} onClick={() => submit('sent')}>
                                <Send />
                                Save and send
                            </Button>
                        </>
                    )}
                </StickyFormBar>
            </form>
        </AppLayout>
    );
}

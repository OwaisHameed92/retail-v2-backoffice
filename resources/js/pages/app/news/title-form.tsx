import { FREQUENCIES, money } from '@/components/app/news/format';
import { type LinkedProduct, type TitleFormProps } from '@/components/app/news/types';
import { MoneyInput, OptionSelect } from '@/components/app/products/fields';
import { CheckField } from '@/components/app/setup/fields';
import { EmptyState } from '@/components/shared/empty-state';
import { FormCard, FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { taxName, taxText } from '@/lib/country';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Info, Link2, Link2Off, PackageSearch, Save, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const EVERY_SHOP = '__every';

type FormData = {
    name: string;
    publisher: string;
    frequency: string;
    supplier_id: string;
    cover_price: string;
    linked_product_id: string;
    linked_barcode: string;
    branch_id: string;
    is_active: boolean;
};

function ProductLine({ product, action }: { product: LinkedProduct; action: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-3 py-2">
            <div className="grid min-w-0 leading-5">
                <span className="truncate font-medium">{product.name}</span>
                <span className="text-muted-foreground truncate text-xs">
                    {[product.sku, product.barcode, product.price ? money(product.price) : null, product.vat].filter(Boolean).join(' · ')}
                </span>
            </div>
            {action}
        </div>
    );
}

/** Add or change a news title (module 5.8). The tills of the shop(s) it is for get it at their next sync. */
const zeroName = (rate: TitleFormProps['zeroVatRate']) => (rate ? `${rate.name} (0%, receipt letter ${rate.code})` : taxText('a 0% VAT rate'));

/** ANSWERS-2026-10-01 §5: no linked product = no VAT line on the till; UK newspapers are zero-rated. */
function VatHint({ linked, zeroVatRate }: { linked: LinkedProduct | null; zeroVatRate: TitleFormProps['zeroVatRate'] }) {
    if (linked && linked.zeroRated !== false) {
        return null;
    }

    return (
        <Alert variant="warning">
            <TriangleAlert />
            <AlertDescription>
                {linked
                    ? `${linked.name} is on ${linked.vat ?? taxText('a VAT rate above 0%')}. Newspapers are zero-rated in the UK: give the product ${zeroName(zeroVatRate)} on its product page.`
                    : `No product linked, so the till makes no ${taxName()} line for this title. Link a product with ${zeroName(zeroVatRate)}: newspapers are zero-rated in the UK.`}
            </AlertDescription>
        </Alert>
    );
}

export default function NewsTitleForm({ title, defaultShopId, linkedProduct, search, results, frequencies, zeroVatRate, shops, suppliers, can }: TitleFormProps) {
    const editing = title !== null;
    const form = useForm<FormData>({
        name: title?.name ?? '',
        publisher: title?.publisher ?? '',
        frequency: title?.frequency ?? 'daily',
        supplier_id: title?.supplierId ?? '',
        cover_price: title?.coverPrice ?? '',
        linked_product_id: title?.linkedProductId ?? '',
        linked_barcode: title?.linkedBarcode ?? '',
        branch_id: title ? title.shopId : defaultShopId,
        is_active: title?.isActive ?? true,
    });
    const [linked, setLinked] = useState<LinkedProduct | null>(linkedProduct);
    const [query, setQuery] = useState(search ?? '');
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    useEffect(() => () => clearTimeout(timer.current), []);

    const shopOptions = [
        ...(can.everyShop ? [{ value: EVERY_SHOP, label: 'Every shop' }] : []),
        ...shops.map((s) => ({ value: s.id, label: `${s.name} (${s.code})` })),
    ];
    const moving = editing && title.shopId !== form.data.branch_id;
    const shopName = (id: string) => (id === '' ? 'every shop' : (shops.find((s) => s.id === id)?.name ?? 'the shop'));

    const link = (product: LinkedProduct | null) => {
        setLinked(product);
        form.setData((data) => ({
            ...data,
            linked_product_id: product?.id ?? '',
            linked_barcode: product?.barcode ?? (product ? data.linked_barcode : ''),
        }));
    };
    const submit = () => {
        if (editing) {
            form.put(route('app.news.titles.update', title.id), { preserveScroll: true });
        } else {
            form.post(route('app.news.titles.store'), { preserveScroll: true });
        }
    };
    const heading = editing ? `Edit ${title.name}` : 'Add a title';

    return (
        <AppLayout>
            <Head title={heading} />

            <PageHeader
                title={heading}
                back={{ href: route('app.news.index', 'titles'), label: 'Titles' }}
                description="A paper or magazine your tills sell. Its shop's tills (or every shop's) get it at their next sync."
            />

            {moving && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>
                        Moving this title from {shopName(title.shopId)} to {shopName(form.data.branch_id)}. The tills it leaves remove it at their
                        next sync.
                    </AlertDescription>
                </Alert>
            )}

            <form
                className="grid gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <FormCard>
                    <FormSection title="Title" description="How it appears on the till and who publishes it.">
                        <FormGrid>
                            <FormField id="name" label="Name" error={form.errors.name}>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    maxLength={120}
                                    placeholder="e.g. The Guardian"
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    aria-invalid={Boolean(form.errors.name) || undefined}
                                />
                            </FormField>
                            <FormField id="publisher" label="Publisher" optional error={form.errors.publisher}>
                                <Input
                                    id="publisher"
                                    value={form.data.publisher}
                                    maxLength={120}
                                    placeholder="e.g. Guardian Media Group"
                                    onChange={(e) => form.setData('publisher', e.target.value)}
                                />
                            </FormField>
                            <FormField id="frequency" label="Published" error={form.errors.frequency}>
                                <OptionSelect
                                    id="frequency"
                                    value={form.data.frequency}
                                    onChange={(value) => form.setData('frequency', value)}
                                    options={frequencies.map((f) => ({ value: f, label: FREQUENCIES[f] ?? f }))}
                                />
                            </FormField>
                            <FormField id="cover_price" label="Cover price" error={form.errors.cover_price} help={taxText('What the customer pays, inc. VAT.')}>
                                <MoneyInput
                                    id="cover_price"
                                    value={form.data.cover_price}
                                    invalid={Boolean(form.errors.cover_price)}
                                    onChange={(e) => form.setData('cover_price', e.target.value)}
                                />
                            </FormField>
                        </FormGrid>
                    </FormSection>

                    <FormSection title="Supply" description="Where it is sold and the wholesaler that delivers it and takes returns.">
                        <FormGrid>
                            <FormField
                                id="branch_id"
                                label="Sold at"
                                error={form.errors.branch_id}
                                help={can.everyShop ? 'Every shop, or one shop only.' : 'You can keep titles for your own shop.'}
                            >
                                <OptionSelect
                                    id="branch_id"
                                    value={form.data.branch_id === '' ? EVERY_SHOP : form.data.branch_id}
                                    invalid={Boolean(form.errors.branch_id)}
                                    onChange={(value) => form.setData('branch_id', value === EVERY_SHOP ? '' : value)}
                                    options={shopOptions}
                                    placeholder="Choose a shop"
                                />
                            </FormField>
                            <FormField id="supplier_id" label="Wholesaler" error={form.errors.supplier_id}>
                                <OptionSelect
                                    id="supplier_id"
                                    value={form.data.supplier_id}
                                    invalid={Boolean(form.errors.supplier_id)}
                                    onChange={(value) => form.setData('supplier_id', value)}
                                    options={suppliers.map((s) => ({ value: s.id, label: s.name }))}
                                    placeholder="Choose a wholesaler"
                                />
                            </FormField>
                            {editing && (
                                <CheckField
                                    id="is_active"
                                    label="On sale"
                                    help="Untick to archive it: the tills stop offering it; its history stays."
                                    checked={form.data.is_active}
                                    onChange={(checked) => form.setData('is_active', checked)}
                                />
                            )}
                        </FormGrid>
                    </FormSection>

                    <FormSection
                        title="Till product"
                        description={taxText("Link the product the till scans for this title. A title has no VAT of its own: the till uses the linked product's VAT rate.")}
                    >
                        <div className="grid gap-3">
                            <VatHint linked={linked} zeroVatRate={zeroVatRate} />
                            {linked ? (
                                <ProductLine
                                    product={linked}
                                    action={
                                        <Button type="button" variant="outline" size="sm" onClick={() => link(null)}>
                                            <Link2Off />
                                            Unlink
                                        </Button>
                                    }
                                />
                            ) : (
                                <p className="text-muted-foreground text-sm">No product linked.</p>
                            )}
                            {form.errors.linked_product_id && <p className="text-destructive text-sm">{form.errors.linked_product_id}</p>}
                            <FormGrid>
                                <FormField id="product_search" label="Find a product" optional>
                                    <Input
                                        id="product_search"
                                        value={query}
                                        placeholder="Name, SKU or barcode"
                                        onChange={(e) => {
                                            const value = e.target.value;
                                            setQuery(value);
                                            clearTimeout(timer.current);
                                            timer.current = setTimeout(
                                                () =>
                                                    router.get(
                                                        window.location.pathname,
                                                        { q: value },
                                                        {
                                                            only: ['results', 'search'],
                                                            preserveState: true,
                                                            preserveScroll: true,
                                                            replace: true,
                                                        },
                                                    ),
                                                300,
                                            );
                                        }}
                                    />
                                </FormField>
                                <FormField
                                    id="linked_barcode"
                                    label="Barcode"
                                    optional
                                    error={form.errors.linked_barcode}
                                    help="Filled from the product; change it for a title barcode."
                                >
                                    <Input
                                        id="linked_barcode"
                                        value={form.data.linked_barcode}
                                        maxLength={64}
                                        inputMode="numeric"
                                        onChange={(e) => form.setData('linked_barcode', e.target.value)}
                                    />
                                </FormField>
                            </FormGrid>
                            {query !== '' &&
                                (results.length === 0 ? (
                                    <EmptyState
                                        icon={PackageSearch}
                                        title="No products found"
                                        body="Try another name, SKU or barcode."
                                        className="py-6"
                                    />
                                ) : (
                                    <div className="divide-y rounded-md border px-3">
                                        {results.map((p) => (
                                            <ProductLine
                                                key={p.id}
                                                product={p}
                                                action={
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={linked?.id === p.id}
                                                        onClick={() => link(p)}
                                                    >
                                                        <Link2 />
                                                        {linked?.id === p.id ? 'Linked' : 'Link'}
                                                    </Button>
                                                }
                                            />
                                        ))}
                                    </div>
                                ))}
                        </div>
                    </FormSection>
                </FormCard>

                <StickyFormBar message={editing ? (form.isDirty ? 'You have unsaved changes.' : 'No changes yet.') : 'New title.'}>
                    <Button variant="outline" asChild>
                        <Link href={route('app.news.index', 'titles')}>Cancel</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        <Save />
                        {editing ? 'Save changes' : 'Add title'}
                    </Button>
                </StickyFormBar>
            </form>
        </AppLayout>
    );
}

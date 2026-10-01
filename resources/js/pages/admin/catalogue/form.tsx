import { MasterFields } from '@/components/admin/catalogue/master-fields';
import { SOURCE_TONES, type MasterFormProps, type MasterValues } from '@/components/admin/catalogue/types';
import { formatDateTime } from '@/components/admin/format';
import { formatMoney } from '@/components/app/products/fields';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { FormCard } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { GitMerge, Info, LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

/** Add or edit one master catalogue product; merge it into a duplicate. */
export default function MasterProductFormPage({ product, values, options }: MasterFormProps) {
    const { data, setData, post, put, processing, errors, isDirty } = useForm<MasterValues>(values);
    const set = <K extends keyof MasterValues>(key: K, value: MasterValues[K]) => setData(key, value as never);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (product) {
            put(route('admin.catalogue.update', product.id), { preserveScroll: true });
        } else {
            post(route('admin.catalogue.store'), { preserveScroll: true });
        }
    };

    const merge = (keepId: string) =>
        new Promise((resolve) => router.post(route('admin.catalogue.merge', product?.id ?? ''), { keep: keepId }, { onFinish: resolve }));

    return (
        <AdminLayout
            width="narrow"
            breadcrumbs={[{ title: 'Catalogue', href: route('admin.catalogue.index') }, { title: product?.name ?? 'Add product' }]}
        >
            <Head title={product?.name ?? 'Add catalogue product'} />
            <PageHeader
                title={product?.name ?? 'Add catalogue product'}
                status={product ? <StatusBadge status={product.source} label={product.sourceLabel} tone={SOURCE_TONES[product.source]} /> : undefined}
                description={
                    product
                        ? `${product.sourceRef ?? product.sourceLabel} · last updated ${formatDateTime(product.updatedAt)}`
                        : 'Businesses find it when they search the catalogue or scan its barcode on the product form.'
                }
                back={{ href: route('admin.catalogue.index'), label: 'Catalogue' }}
            />

            {product?.mergedInto && (
                <Alert variant="info">
                    <GitMerge />
                    <AlertTitle>Merged</AlertTitle>
                    <AlertDescription>
                        <span>
                            This barcode now finds{' '}
                            <Link href={route('admin.catalogue.edit', product.mergedInto.id)} className="underline underline-offset-4">
                                {product.mergedInto.name}
                            </Link>{' '}
                            ({product.mergedInto.barcode}). It is no longer listed or offered to shops.
                        </span>
                    </AlertDescription>
                </Alert>
            )}

            {product && product.sameName.length > 0 && !product.mergedInto && (
                <SectionCard
                    title="Possible duplicates"
                    description="Other products with the same name. Merge this one into the one to keep: its barcode will then find that product."
                    flush
                >
                    <ul className="divide-y">
                        {product.sameName.map((other) => (
                            <li key={other.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
                                <div className="min-w-0 flex-1">
                                    <Link href={route('admin.catalogue.edit', other.id)} className="text-sm font-medium hover:underline">
                                        {other.name}
                                    </Link>
                                    <p className="text-muted-foreground font-mono text-xs tabular-nums">
                                        {[other.barcode, other.size, other.rrp ? formatMoney(other.rrp) : null].filter(Boolean).join(' · ')}
                                    </p>
                                </div>
                                <StatusBadge status={other.source} label={other.sourceLabel} tone={SOURCE_TONES[other.source]} />
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="outline" size="sm">
                                            <GitMerge />
                                            Keep that one
                                        </Button>
                                    }
                                    title={`Merge into ${other.barcode}?`}
                                    description={`${product.barcode} will find “${other.name}” (${other.barcode}). Details that one lacks are copied from this one. This product is then no longer listed.`}
                                    confirmLabel="Merge"
                                    onConfirm={() => merge(other.id)}
                                />
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            )}

            {product && product.aliases.length > 0 && (
                <Alert variant="info">
                    <Info />
                    <AlertTitle>Also found by</AlertTitle>
                    <AlertDescription>
                        <span className="font-mono tabular-nums">{product.aliases.join(', ')}</span>
                    </AlertDescription>
                </Alert>
            )}

            <form onSubmit={submit} className="grid gap-6" noValidate>
                <FormCard>
                    <MasterFields data={data} setData={set} errors={errors} options={options} />
                </FormCard>
                <StickyFormBar message={isDirty ? 'You have unsaved changes.' : 'Shops see changes the next time they search or scan.'}>
                    <Button variant="outline" asChild>
                        <Link href={route('admin.catalogue.index')}>Cancel</Link>
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                        {product ? 'Save changes' : 'Add product'}
                    </Button>
                </StickyFormBar>
            </form>
        </AdminLayout>
    );
}

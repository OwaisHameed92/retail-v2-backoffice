import { BarcodeLookup } from '@/components/app/catalogue/barcode-lookup';
import { BarcodeEditor } from '@/components/app/products/barcode-editor';
import { BasicsSection, GroupSection, PricingSection } from '@/components/app/products/product-form-basics';
import { RulesSection, TillSection } from '@/components/app/products/product-form-rules';
import { StockSection, UnitEditor } from '@/components/app/products/product-form-stock';
import { type ProductFormProps, type ProductValues } from '@/components/app/products/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { FormCard } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { relativeTime } from '@/lib/relative-time';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Archive, ArchiveRestore, Info, LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

export default function ProductFormPage({ product, values, options, canManage }: ProductFormProps) {
    const { data, setData, post, put, processing, errors, isDirty } = useForm<ProductValues>(values);
    const set = <K extends keyof ProductValues>(key: K, value: ProductValues[K]) => setData(key, value as never);
    const section = { data, setData: set, errors: errors as Record<string, string>, options };
    const missing = options.vatRates.length === 0 || options.departments.length === 0;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (product) {
            put(route('app.products.update', product.id), { preserveScroll: true });
        } else {
            post(route('app.products.store'), { preserveScroll: true });
        }
    };

    const title = product ? product.name : 'Add product';
    const meta = product
        ? [
              `Last changed ${product.updatedAt ? relativeTime(product.updatedAt) : 'at the till'} by ${product.lastChangedBy}`,
              product.nearestExpiryDate ? `Nearest expiry ${product.nearestExpiryDate}` : null,
          ]
              .filter(Boolean)
              .join(' · ')
        : 'Every shop gets it at its next sync.';

    return (
        <AppLayout>
            <Head title={title} />
            <div className="mx-auto grid w-full max-w-5xl gap-6">
                <PageHeader
                    title={title}
                    status={product ? <StatusBadge status={product.isActive ? 'active' : 'archived'} /> : undefined}
                    description={meta}
                    back={{ href: route('app.products.index'), label: 'Products' }}
                    actions={
                        product && canManage ? (
                            product.isActive ? (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="outline">
                                            <Archive />
                                            Archive
                                        </Button>
                                    }
                                    title={`Archive ${product.name}?`}
                                    description="Your tills stop offering it at their next sync. Its sales history stays, and you can restore it at any time."
                                    confirmLabel="Archive product"
                                    destructive
                                    onConfirm={() =>
                                        new Promise((resolve) =>
                                            router.post(route('app.products.archive', product.id), {}, { preserveScroll: true, onFinish: resolve }),
                                        )
                                    }
                                />
                            ) : (
                                <Button
                                    variant="outline"
                                    onClick={() => router.post(route('app.products.restore', product.id), {}, { preserveScroll: true })}
                                >
                                    <ArchiveRestore />
                                    Restore
                                </Button>
                            )
                        ) : undefined
                    }
                />

                {!canManage && (
                    <Alert variant="info">
                        <Info />
                        <AlertTitle>View only</AlertTitle>
                        <AlertDescription>
                            Your role can look at products but not change them. Ask the business owner if you need to.
                        </AlertDescription>
                    </Alert>
                )}
                {canManage && missing && (
                    <Alert variant="warning">
                        <Info />
                        <AlertTitle>{options.vatRates.length === 0 ? 'No VAT rates yet' : 'No departments yet'}</AlertTitle>
                        <AlertDescription>
                            {options.vatRates.length === 0 ? (
                                'Your VAT rates arrive from your till at its first sync. Connect a till first, then add products here.'
                            ) : (
                                <span>
                                    Add a department and a category first.{' '}
                                    <Link href={route('app.products.groups')} className="underline underline-offset-4">
                                        Manage departments
                                    </Link>
                                </span>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {!product && canManage && !missing && <BarcodeLookup data={data} setData={set} />}

                <form onSubmit={submit} className="grid gap-6" noValidate>
                    <fieldset disabled={!canManage} className="contents">
                        <FormCard>
                            <BasicsSection {...section} />
                            <GroupSection {...section} />
                            <PricingSection {...section} />
                            <BarcodeEditor data={data} setData={set} errors={section.errors} disabled={!canManage} />
                            <StockSection {...section} />
                            {(canManage || data.units.length > 0) && <UnitEditor {...section} />}
                            <RulesSection {...section} />
                            <TillSection {...section} />
                        </FormCard>
                    </fieldset>

                    {canManage && (
                        <StickyFormBar
                            message={isDirty ? 'You have unsaved changes.' : 'Prices are in pounds. Changes reach your tills at their next sync.'}
                        >
                            <Button variant="outline" asChild>
                                <Link href={route('app.products.index')}>Cancel</Link>
                            </Button>
                            <Button type="submit" disabled={processing || missing}>
                                {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                                {product ? 'Save changes' : 'Add product'}
                            </Button>
                        </StickyFormBar>
                    )}
                </form>
            </div>
        </AppLayout>
    );
}

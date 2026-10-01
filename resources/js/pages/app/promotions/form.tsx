import { OFFER_TONES } from '@/components/app/pricing/format';
import { type PromotionFormProps, type PromotionValues } from '@/components/app/pricing/types';
import { OfferItems } from '@/components/app/promotions/offer-items';
import { DAYS, tiersString } from '@/components/app/promotions/offer-pickers';
import { DealSection, ITEM_TYPES, TargetSection, WhenSection } from '@/components/app/promotions/offer-sections';
import { CheckField } from '@/components/app/setup/fields';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { FormCard, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CircleStop, Info, LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

const blank = (today: string, shop: string | null): PromotionValues => ({
    name: '',
    type: 'percentOff',
    scope: 'product',
    target_id: '',
    percent: '',
    amount_off: '',
    deal_price: '',
    buy_quantity: '',
    get_quantity: '',
    min_quantity: '1',
    priority: '0',
    allow_stack: false,
    is_exclusive: false,
    max_redemptions_per_sale: '',
    max_redemptions_total: '',
    requires_coupon: false,
    coupon_code: '',
    branch_id: shop ?? '',
    is_hfss_safe: false,
    effective_from: today,
    effective_to: '',
    time_from: '',
    time_to: '',
    is_active: true,
    items: [],
    price_tiers: [
        { quantity: '2', price: '' },
        { quantity: '3', price: '' },
    ],
    days: DAYS.map((d) => d.value),
});

export default function PromotionFormPage({ promotion, options, restrictedShop, canEdit, today }: PromotionFormProps) {
    const initial: PromotionValues = promotion ?? blank(today, restrictedShop);
    const { data, setData, post, put, processing, errors, isDirty, transform } = useForm<PromotionValues>(initial);
    const set = <K extends keyof PromotionValues>(key: K, value: PromotionValues[K]) => setData(key, value as never);
    const section = { data, set, errors: errors as Record<string, string>, options };
    const itemType = ITEM_TYPES.includes(data.type);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((values) => ({
            ...values,
            branch_id: values.branch_id || null,
            items: ITEM_TYPES.includes(values.type) ? values.items : [],
            price_tiers: values.type === 'quantityPrice' ? tiersString(values.price_tiers) : null,
        }));
        if (promotion) {
            put(route('app.promotions.update', promotion.id), { preserveScroll: true });
        } else {
            post(route('app.promotions.store'), { preserveScroll: true });
        }
    };

    const title = promotion ? promotion.name : 'Add offer';

    return (
        <AppLayout>
            <Head title={title} />
            <div className="mx-auto grid w-full max-w-5xl gap-6">
                <PageHeader
                    title={title}
                    status={promotion ? <StatusBadge status={promotion.status} tones={OFFER_TONES} /> : undefined}
                    description={promotion ? `${promotion.deal} · used ${promotion.redemptions} time${promotion.redemptions === 1 ? '' : 's'}${promotion.isGroupOffer ? ' · group offer' : ''}` : 'Every till gets it at its next sync.'}
                    back={{ href: route('app.promotions.index'), label: 'Promotions' }}
                    actions={
                        promotion && canEdit && promotion.status !== 'ended' ? (
                            <ConfirmDialog
                                trigger={
                                    <Button variant="outline">
                                        <CircleStop />
                                        End now
                                    </Button>
                                }
                                title={`End ${promotion.name} now?`}
                                description="Tills stop it at their next sync. You can switch it on again here."
                                confirmLabel="End offer"
                                destructive
                                onConfirm={() => new Promise((resolve) => router.post(route('app.promotions.end', promotion.id), {}, { preserveScroll: true, onFinish: resolve }))}
                            />
                        ) : undefined
                    }
                />

                {!canEdit && (
                    <Alert variant="info">
                        <Info />
                        <AlertTitle>View only</AlertTitle>
                        <AlertDescription>
                            {restrictedShop !== null ? 'This offer runs in more than your shop, so only someone who manages all shops can change it.' : 'Your role can look at offers but not change them.'}
                        </AlertDescription>
                    </Alert>
                )}

                <form onSubmit={submit} className="grid gap-6" noValidate>
                    <fieldset disabled={!canEdit} className="contents">
                        <FormCard>
                            <DealSection {...section} />
                            {itemType ? <OfferItems {...section} disabled={!canEdit} /> : <TargetSection {...section} />}
                            <WhenSection {...section} restricted={restrictedShop !== null} />
                            <FormSection title="Status">
                                <CheckField id="is_active" label="Active" help="Switch off to pause it on every till without deleting it." checked={data.is_active} onChange={(v) => set('is_active', v)} />
                            </FormSection>
                        </FormCard>
                    </fieldset>

                    {canEdit && (
                        <StickyFormBar message={isDirty ? 'You have unsaved changes.' : 'Prices are in pounds. Changes reach your tills at their next sync.'}>
                            <Button variant="outline" asChild>
                                <Link href={route('app.promotions.index')}>Cancel</Link>
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                                {promotion ? 'Save changes' : 'Add offer'}
                            </Button>
                        </StickyFormBar>
                    )}
                </form>
            </div>
        </AppLayout>
    );
}

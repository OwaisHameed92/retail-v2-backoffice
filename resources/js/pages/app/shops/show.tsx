import { EndsAtText, LicenceKindPill } from '@/components/app/shops/licence-bits';
import { RequestDialog } from '@/components/app/shops/request-dialog';
import { RequestsCard } from '@/components/app/shops/requests-card';
import { TillsCard } from '@/components/app/shops/tills-card';
import { blankNulls, type ShopForm, type ShopShowProps } from '@/components/app/shops/types';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { FormCard, FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge, StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { vatNumberLabel } from '@/lib/country';
import { Head, useForm } from '@inertiajs/react';
import { Check, LoaderCircle, Plus, Store } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';
import { postcodeLabel, townHint, townNeededWithAddress } from '@/lib/country-address';

export default function ShopShow({ shop, business, licence, tills, health, requests, requestOptions, can }: ShopShowProps) {
    const [asking, setAsking] = useState(false);
    const address = [shop.address, shop.town, shop.postcode].filter(Boolean).join(', ');

    return (
        <AppLayout>
            <Head title={shop.name} />
            <PageHeader
                back={{ href: route('app.shops.index'), label: 'Shops and tills' }}
                title={shop.name}
                status={<StatusBadge status={shop.isActive ? 'active' : 'inactive'} label={shop.isActive ? 'Open' : 'Closed'} />}
                media={<InitialsAvatar name={shop.name} shape="square" size="lg" icon={Store} />}
                description={address || 'No address yet'}
                meta={
                    <span>
                        Code <span className="font-mono">{shop.code}</span> · {shop.nation}
                    </span>
                }
                actions={
                    can.ask ? (
                        <Button onClick={() => setAsking(true)}>
                            <Plus aria-hidden />
                            Ask for more tills
                        </Button>
                    ) : undefined
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="grid min-w-0 content-start gap-6 lg:col-span-2">
                    <TillsCard tills={tills} health={health} />
                    <ShopDetailsForm shop={shop} business={business} canEdit={can.edit} />
                </div>

                <div className="grid min-w-0 content-start gap-6">
                    <SectionCard title="Licence" description="Set by Switch & Save. Ask us to change it.">
                        <dl className="grid gap-4 text-sm">
                            <div className="flex items-center justify-between gap-3">
                                <dt className="text-muted-foreground">Type</dt>
                                <dd>
                                    <LicenceKindPill kind={licence.kind} />
                                </dd>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <dt className="text-muted-foreground">Tills</dt>
                                <dd className="font-medium tabular-nums">
                                    {licence.tillsActive} of {licence.tillsAllowed} allowed
                                </dd>
                            </div>
                            <div className="flex items-start justify-between gap-3">
                                <dt className="text-muted-foreground">Next end date</dt>
                                <dd className="text-right">
                                    <EndsAtText iso={licence.nextEndsAt} prefix="" />
                                </dd>
                            </div>
                            <div className="grid gap-2">
                                <dt className="text-muted-foreground">Features</dt>
                                <dd className="flex flex-wrap gap-1.5">
                                    {licence.features.length === 0 ? (
                                        <span className="text-muted-foreground">None yet</span>
                                    ) : (
                                        licence.features.map((feature) => (
                                            <StatusPill key={feature.value} tone="success">
                                                <Check className="size-3" aria-hidden />
                                                {feature.label}
                                            </StatusPill>
                                        ))
                                    )}
                                </dd>
                            </div>
                        </dl>
                        {can.ask && (
                            <Button variant="outline" className="mt-5 w-full" onClick={() => setAsking(true)}>
                                <Plus aria-hidden />
                                Ask for more tills
                            </Button>
                        )}
                    </SectionCard>
                    <RequestsCard requests={requests} />
                </div>
            </div>

            <RequestDialog open={asking} onOpenChange={setAsking} options={requestOptions} shopId={shop.id} />
        </AppLayout>
    );
}

function ShopDetailsForm({ shop, business, canEdit }: Pick<ShopShowProps, 'shop' | 'business'> & { canEdit: boolean }) {
    const initial = blankNulls({
        name: shop.name,
        address: shop.address,
        town: shop.town,
        postcode: shop.postcode,
        phone: shop.phone,
        vat_number: shop.vatNumber,
        receipt_footer: shop.receiptFooter,
    });
    const { data, setData, put, processing, errors, isDirty, reset, setDefaults } = useForm<ShopForm>(initial);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('app.shops.update', shop.id), { preserveScroll: true, onSuccess: () => setDefaults() });
    };

    const text = (key: keyof ShopForm, label: string, props: { optional?: boolean; help?: string; type?: string; upper?: boolean; className?: string } = {}) => (
        <FormField id={key} label={label} optional={props.optional} help={props.help} error={errors[key]} className={props.className}>
            <Input
                id={key}
                type={props.type ?? 'text'}
                value={data[key]}
                disabled={!canEdit}
                onChange={(e) => setData(key, props.upper ? e.target.value.toUpperCase() : e.target.value)}
                aria-invalid={errors[key] ? true : undefined}
            />
        </FormField>
    );

    return (
        <form onSubmit={submit} noValidate>
            <FormCard>
                <FormSection
                    title="Shop details"
                    description={canEdit ? "Printed on this shop's receipts. The till gets changes at its next sync." : 'Only the owner or a manager can change these.'}
                >
                    <FormGrid>
                        {text('name', 'Shop name')}
                        {text('phone', 'Phone', { optional: true, type: 'tel' })}
                        {text('vat_number', vatNumberLabel(), {
                            optional: true,
                            upper: true,
                            help: business.vatNumber ? `Leave blank if the shop uses the business's ${business.vatNumber}.` : undefined,
                        })}
                    </FormGrid>
                </FormSection>
                <FormSection title="Address">
                    <FormGrid>
                        <FormField id="address" label="Address" optional error={errors.address} className="sm:col-span-2">
                            <Textarea
                                id="address"
                                rows={2}
                                value={data.address}
                                disabled={!canEdit}
                                onChange={(e) => setData('address', e.target.value)}
                                aria-invalid={errors.address ? true : undefined}
                            />
                        </FormField>
                        {text('town', 'Town', { optional: !townNeededWithAddress(), help: townHint() })}
                        {text('postcode', postcodeLabel(), { optional: true, upper: true })}
                    </FormGrid>
                </FormSection>
                <FormSection title="Receipt">
                    <FormField
                        id="receipt_footer"
                        label="Receipt footer"
                        optional
                        error={errors.receipt_footer}
                        help={business.receiptFooter ? `Blank uses the business's footer: “${business.receiptFooter}”.` : 'A thank-you line or your returns policy.'}
                    >
                        <Textarea
                            id="receipt_footer"
                            rows={2}
                            maxLength={200}
                            value={data.receipt_footer}
                            disabled={!canEdit}
                            onChange={(e) => setData('receipt_footer', e.target.value)}
                            aria-invalid={errors.receipt_footer ? true : undefined}
                        />
                    </FormField>
                </FormSection>
                {canEdit && (
                    <div className="flex items-center justify-end gap-2 border-t px-5 py-4">
                        <Button type="button" variant="outline" disabled={!isDirty || processing} onClick={() => reset()}>
                            Discard
                        </Button>
                        <Button type="submit" disabled={!isDirty || processing}>
                            {processing && <LoaderCircle className="animate-spin" aria-hidden />}
                            Save shop
                        </Button>
                    </div>
                )}
            </FormCard>
        </form>
    );
}

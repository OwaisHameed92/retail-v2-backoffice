import { ShopsTabs } from '@/components/app/shops/licence-bits';
import { blankNulls, type BusinessForm, type BusinessPageProps, shopDate } from '@/components/app/shops/types';
import { DescriptionList } from '@/components/shared/description-list';
import { FormCard, FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { companyNumberLabel, taxIdFor, vatNumberLabel } from '@/lib/country';
import { Head, useForm } from '@inertiajs/react';
import { Info, LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';
import { postcodeLabel, townHint, townNeededWithAddress } from '@/lib/country-address';

export default function BusinessDetailsPage({ business, facts, can }: BusinessPageProps) {
    const { data, setData, put, processing, errors, isDirty, reset, setDefaults } = useForm<BusinessForm>(blankNulls(business));
    const strn = taxIdFor('strn');

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('app.shops.business.update'), { preserveScroll: true, onSuccess: () => setDefaults() });
    };

    const text = (key: keyof BusinessForm, label: string, props: { optional?: boolean; help?: string; type?: string; upper?: boolean } = {}) => (
        <FormField id={key} label={label} optional={props.optional} help={props.help} error={errors[key]}>
            <Input
                id={key}
                type={props.type ?? 'text'}
                value={data[key] ?? ''}
                disabled={!can.edit}
                onChange={(e) => setData(key, props.upper ? e.target.value.toUpperCase() : e.target.value)}
                aria-invalid={errors[key] ? true : undefined}
            />
        </FormField>
    );

    return (
        <AppLayout>
            <Head title="Business details" />
            <PageHeader
                title="Shops and tills"
                description="Your business's details as every till prints them. Your plan and limits are set by Switch & Save."
                tabs={<ShopsTabs active="business" />}
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <form onSubmit={submit} noValidate className="grid min-w-0 content-start gap-6 lg:col-span-2">
                    {!can.edit && (
                        <Alert variant="info">
                            <Info aria-hidden />
                            <AlertDescription>Only the business owner can change these details.</AlertDescription>
                        </Alert>
                    )}
                    <FormCard>
                        <FormSection title="Business" description="Every till gets changes at its next sync.">
                            <FormGrid>
                                {text('name', 'Trading name')}
                                {text('legal_name', 'Legal name', { optional: true, help: 'As registered, if different.' })}
                                {text('vat_number', vatNumberLabel(), { optional: true, upper: true })}
                                {strn && text('strn', strn.label, { optional: true })}
                                {text('company_number', companyNumberLabel(), { optional: true, upper: true })}
                            </FormGrid>
                        </FormSection>
                        <FormSection title="Contact">
                            <FormGrid>
                                {text('phone', 'Phone', { optional: true, type: 'tel' })}
                                {text('email', 'Email', { optional: true, type: 'email' })}
                                <FormField id="address" label="Registered address" optional error={errors.address} className="sm:col-span-2">
                                    <Textarea
                                        id="address"
                                        rows={2}
                                        value={data.address}
                                        disabled={!can.edit}
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
                                help="Used by every shop that has no footer of its own."
                            >
                                <Textarea
                                    id="receipt_footer"
                                    rows={2}
                                    maxLength={200}
                                    value={data.receipt_footer}
                                    disabled={!can.edit}
                                    onChange={(e) => setData('receipt_footer', e.target.value)}
                                    aria-invalid={errors.receipt_footer ? true : undefined}
                                />
                            </FormField>
                        </FormSection>
                        {can.edit && (
                            <div className="flex items-center justify-end gap-2 border-t px-5 py-4">
                                <Button type="button" variant="outline" disabled={!isDirty || processing} onClick={() => reset()}>
                                    Discard
                                </Button>
                                <Button type="submit" disabled={!isDirty || processing}>
                                    {processing && <LoaderCircle className="animate-spin" aria-hidden />}
                                    Save business details
                                </Button>
                            </div>
                        )}
                    </FormCard>
                </form>

                <SectionCard title="Your account" description="Call Switch & Save to change these.">
                    <DescriptionList
                        layout="rows"
                        items={[
                            { label: 'Status', value: <StatusBadge status={facts.status} /> },
                            { label: 'Business type', value: facts.businessType },
                            { label: 'Shops', value: `${facts.shops} of ${facts.shopsAllowed} allowed` },
                            { label: 'Customer since', value: shopDate(facts.customerSince) },
                        ]}
                    />
                </SectionCard>
            </div>
        </AppLayout>
    );
}

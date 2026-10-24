import { OnlineStoreTabs } from '@/components/app/online-store/online-store-tabs';
import { type LegalPageKey, type LegalProps, type TraderDetails } from '@/components/app/online-store/types';
import { FormCard, FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { ExternalLink, Info, LoaderCircle, TriangleAlert } from 'lucide-react';

type LegalForm = { [K in keyof TraderDetails]: string } & { extra: Record<LegalPageKey, string> };

const EXTRA_HELP: Record<LegalPageKey, string> = {
    contact: 'For example how to reach you about a big order, or parking at the shop.',
    privacy: 'Anything else you do with customers’ details, for example a loyalty scheme.',
    terms: 'Your own extra terms, for example a returns policy that gives more than the law.',
    cookies: 'Only needed if you add something to the store that sets cookies.',
};

function TextField({
    form,
    field,
    label,
    help,
    optional = false,
    disabled,
    type = 'text',
    max,
}: {
    form: ReturnType<typeof useForm<LegalForm>>;
    field: keyof TraderDetails;
    label: string;
    help?: string;
    optional?: boolean;
    disabled: boolean;
    type?: string;
    max: number;
}) {
    const error = (form.errors as Record<string, string | undefined>)[field];

    return (
        <FormField id={field} label={label} optional={optional} help={help} error={error}>
            <Input
                id={field}
                type={type}
                value={form.data[field]}
                maxLength={max}
                disabled={disabled}
                aria-invalid={!!error}
                onChange={(e) => form.setData(field, e.target.value)}
            />
        </FormField>
    );
}

/**
 * OS-11 (audit OSA-2): the trader details UK law asks an online shop to show, and the shop's own text for the
 * generated contact, privacy, terms and cookies pages. Going live needs the required details saved here.
 */
export default function OnlineStoreLegal({ hasStore, canEdit, details, saved, missing, extra, extraMax, pages }: LegalProps) {
    const form = useForm<LegalForm>({
        trading_name: details.trading_name ?? '',
        legal_name: details.legal_name ?? '',
        legal_address: details.legal_address ?? '',
        company_number: details.company_number ?? '',
        vat_number: details.vat_number ?? '',
        contact_email: details.contact_email ?? '',
        contact_phone: details.contact_phone ?? '',
        extra,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const disabled = !canEdit || !hasStore || form.processing;
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(route('app.online-store.legal.update'), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <AppLayout>
            <Head title="Legal & contact" />
            <PageHeader
                title="Online store"
                description="Who you are and how customers reach you. We use these details for your store's contact, privacy, terms and cookies pages."
                status={saved ? <StatusBadge status="done" label="Details saved" tones={{ done: 'success' }} /> : undefined}
                tabs={<OnlineStoreTabs active="legal" />}
            />

            {!hasStore ? (
                <Alert variant="warning">
                    <TriangleAlert />
                    <AlertDescription>Your online store is not switched on yet. Contact Switch &amp; Save to switch it on.</AlertDescription>
                </Alert>
            ) : !saved ? (
                <Alert variant="warning">
                    <TriangleAlert />
                    <AlertDescription>
                        UK law asks every online shop to show who it is. Check the details below (we filled in what we know from your business)
                        and save them before you go live. Still to save: {missing.join(', ')}.
                    </AlertDescription>
                </Alert>
            ) : (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>
                        Your store links to these pages in its footer and at checkout. The wording is a standard UK template; add your own
                        text below if you need to.
                    </AlertDescription>
                </Alert>
            )}

            <form onSubmit={submit} className="grid gap-6">
                <FormCard>
                    <FormSection title="Who you are" description="Shown in the footer of every page and on your contact, terms and privacy pages.">
                        <FormGrid>
                            <TextField form={form} field="trading_name" label="Trading name" help="The name customers know the shop by." disabled={disabled} max={120} />
                            <TextField
                                form={form}
                                field="legal_name"
                                label="Legal name"
                                help="The company's registered name, or the owner's name for a sole trader."
                                disabled={disabled}
                                max={160}
                            />
                        </FormGrid>
                        <FormField
                            id="legal_address"
                            label="Business address"
                            error={errors.legal_address}
                            help="A full postal address. For a company, its registered office."
                        >
                            <Textarea
                                id="legal_address"
                                rows={2}
                                maxLength={500}
                                value={form.data.legal_address}
                                disabled={disabled}
                                aria-invalid={!!errors.legal_address}
                                onChange={(e) => form.setData('legal_address', e.target.value)}
                            />
                        </FormField>
                        <FormGrid>
                            <TextField
                                form={form}
                                field="company_number"
                                label="Company number"
                                optional
                                help="Limited companies and LLPs only."
                                disabled={disabled}
                                max={10}
                            />
                            <TextField form={form} field="vat_number" label="VAT number" optional help="If you are VAT registered." disabled={disabled} max={20} />
                        </FormGrid>
                    </FormSection>

                    <FormSection title="How customers reach you" description="On the contact page and in the terms. Use an address someone reads every day.">
                        <FormGrid>
                            <TextField form={form} field="contact_email" label="Contact email" type="email" disabled={disabled} max={255} />
                            <TextField form={form} field="contact_phone" label="Phone" type="tel" optional disabled={disabled} max={30} />
                        </FormGrid>
                    </FormSection>

                    <FormSection
                        title="Your own text"
                        description="Optional. Added at the end of each page, under the standard wording. Plain text; blank lines start a new paragraph."
                    >
                        {pages.map((page) => (
                            <FormField
                                key={page.key}
                                id={`extra-${page.key}`}
                                label={page.label}
                                optional
                                error={errors[`extra.${page.key}`] ?? (page.key === 'contact' ? errors.extra : undefined)}
                                help={EXTRA_HELP[page.key]}
                                labelAside={
                                    page.url ? (
                                        <a
                                            href={page.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-primary inline-flex items-center gap-1 text-xs font-medium hover:underline"
                                        >
                                            View page
                                            <ExternalLink className="size-3" />
                                        </a>
                                    ) : undefined
                                }
                            >
                                <Textarea
                                    id={`extra-${page.key}`}
                                    rows={3}
                                    maxLength={extraMax}
                                    value={form.data.extra[page.key]}
                                    disabled={disabled}
                                    onChange={(e) => form.setData('extra', { ...form.data.extra, [page.key]: e.target.value })}
                                />
                            </FormField>
                        ))}
                    </FormSection>
                </FormCard>

                {canEdit && hasStore && (form.isDirty || !saved) && (
                    <StickyFormBar message={saved ? 'You have unsaved changes.' : 'Save these details to go live.'}>
                        {form.isDirty && (
                            <Button type="button" variant="outline" disabled={form.processing} onClick={() => form.reset()}>
                                Discard changes
                            </Button>
                        )}
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Save details
                        </Button>
                    </StickyFormBar>
                )}
            </form>
        </AppLayout>
    );
}

import { ColourPicker } from '@/components/app/online-store/colour-picker';
import { ImagePicker } from '@/components/app/online-store/image-picker';
import { OnlineStoreTabs } from '@/components/app/online-store/online-store-tabs';
import { SectionsEditor } from '@/components/app/online-store/sections-editor';
import { putSwitch, StatusPanel } from '@/components/app/online-store/status-panel';
import { TemplatePicker } from '@/components/app/online-store/template-picker';
import { ToggleSwitch } from '@/components/app/online-store/toggle-switch';
import { type BuilderProps, type StoreSectionValue, type TemplateKey } from '@/components/app/online-store/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { FormCard, FormField, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { CircleCheck, CreditCard, ExternalLink, Eye, LoaderCircle, Power, Rocket } from 'lucide-react';
import { useState } from 'react';

type BuilderForm = {
    slug: string;
    template: TemplateKey;
    headline: string;
    brand_colour: string;
    order_email: string;
    sections: StoreSectionValue[];
    logo: File | null;
    banner: File | null;
    remove_logo: boolean;
    remove_banner: boolean;
};

function initial(props: BuilderProps): BuilderForm {
    const { store } = props;

    return {
        slug: store?.slug ?? props.suggested.slug,
        template: store?.template ?? 'freshMarket',
        headline: store?.headline ?? '',
        brand_colour: store?.brandColour ?? '',
        order_email: store?.orderEmail ?? props.suggested.orderEmail ?? '',
        sections: store?.sections ?? props.sectionOptions.map((s) => ({ key: s.key, enabled: true })),
        logo: null,
        banner: null,
        remove_logo: false,
        remove_banner: false,
    };
}

function Builder(props: BuilderProps) {
    const { store, templates, canEdit } = props;
    const form = useForm<BuilderForm>(initial(props));
    const errors = form.errors as Record<string, string | undefined>;
    const [switching, setSwitching] = useState(false);
    const template = templates.find((t) => t.value === form.data.template) ?? templates[0];
    const disabled = !canEdit || form.processing;
    const sectionError = Object.entries(errors).find(([key]) => key.startsWith('sections'))?.[1];

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('app.online-store.update'), { forceFormData: true, preserveScroll: true });
    };
    const setLive = (on: boolean) =>
        new Promise<void>((resolve) => {
            setSwitching(true);
            putSwitch('app.online-store.live', on, () => {
                setSwitching(false);
                resolve();
            });
        });

    return (
        <>
            <PageHeader
                title="Online store"
                description="Pick a look, add your logo and go live. Products, prices and stock come from your tills."
                status={
                    <StatusBadge
                        status={!store ? 'draft' : store.isLive ? 'live' : 'offline'}
                        label={!store ? 'Not set up' : store.isLive ? 'Live' : 'Offline'}
                        tones={{ live: 'success', offline: 'neutral' }}
                    />
                }
                tabs={<OnlineStoreTabs active="store" />}
                actions={
                    store && (
                        <>
                            {store.isLive ? (
                                <Button variant="outline" asChild>
                                    <a href={store.address} target="_blank" rel="noopener noreferrer">
                                        <ExternalLink />
                                        Preview store
                                    </a>
                                </Button>
                            ) : (
                                <Button variant="outline" disabled title="The store opens once it is live">
                                    <Eye />
                                    Preview store
                                </Button>
                            )}
                            {canEdit &&
                                (store.isLive ? (
                                    <ConfirmDialog
                                        trigger={
                                            <Button variant="outline" disabled={switching}>
                                                <Power />
                                                Take offline
                                            </Button>
                                        }
                                        title="Take your store offline?"
                                        description={`${store.address} stops opening and customers cannot place orders. Orders already placed stay on the Online orders board.`}
                                        confirmLabel="Take offline"
                                        destructive
                                        onConfirm={() => setLive(false)}
                                    />
                                ) : (
                                    <ConfirmDialog
                                        trigger={
                                            <Button disabled={switching || form.isDirty} title={form.isDirty ? 'Save your changes first' : undefined}>
                                                <Rocket />
                                                Go live
                                            </Button>
                                        }
                                        title="Go live?"
                                        description={`Customers can open ${store.address} and ${store.takingOrders ? 'place click & collect orders' : 'look around (orders are paused)'}. Share the address on your receipts, social media and shop window.`}
                                        confirmLabel="Go live"
                                        onConfirm={() => setLive(true)}
                                    />
                                ))}
                        </>
                    )
                }
            />

            <StatusPanel store={store} checklist={props.checklist} canEdit={canEdit} />

            {!canEdit && (
                <Alert variant="info">
                    <AlertDescription>You can look at the store settings. Only users who manage every shop can change them.</AlertDescription>
                </Alert>
            )}

            <form onSubmit={submit} className="grid gap-6">
                <FormCard>
                    <FormSection title="Look" description="Each template has the sections that kind of shop needs. You can change it at any time.">
                        <TemplatePicker
                            templates={templates}
                            value={form.data.template}
                            onChange={(v) => form.setData('template', v)}
                            disabled={disabled}
                        />
                        {errors.template && <p className="text-danger-foreground text-[13px]">{errors.template}</p>}
                    </FormSection>

                    <FormSection
                        title="Store address"
                        description="Lower-case letters, numbers and hyphens. Changing it breaks links you have already shared."
                    >
                        <FormField
                            id="slug"
                            label="Address"
                            error={errors.slug}
                            help={
                                <span className="font-mono break-all">
                                    {props.addressPrefix + (form.data.slug.trim().toLowerCase() || 'your-shop')}
                                </span>
                            }
                        >
                            <div className="flex min-w-0 items-stretch">
                                <span
                                    title={props.addressPrefix}
                                    className="bg-muted text-muted-foreground hidden max-w-[60%] shrink-0 overflow-hidden rounded-l-md border border-r-0 px-3 font-mono text-sm leading-[34px] text-ellipsis whitespace-nowrap [direction:rtl] sm:block"
                                >
                                    <bdi dir="ltr">{props.addressPrefix}</bdi>
                                </span>
                                <Input
                                    id="slug"
                                    className="font-mono sm:rounded-l-none"
                                    value={form.data.slug}
                                    maxLength={40}
                                    autoComplete="off"
                                    spellCheck={false}
                                    disabled={disabled}
                                    aria-invalid={!!errors.slug}
                                    aria-describedby={errors.slug ? 'slug-error' : 'slug-help'}
                                    onChange={(e) => form.setData('slug', e.target.value.toLowerCase().replace(/\s+/g, '-'))}
                                />
                            </div>
                        </FormField>
                    </FormSection>

                    <FormSection title="Brand" description="Your logo, main colour, banner photo and headline on the home page.">
                        <FormField id="logo" label="Logo" optional error={errors.logo}>
                            <ImagePicker
                                label="Logo"
                                current={store?.logoUrl ?? null}
                                file={form.data.logo}
                                removed={form.data.remove_logo}
                                onPick={(f) => form.setData('logo', f)}
                                onRemove={(r) => form.setData('remove_logo', r)}
                                error={errors.logo}
                                disabled={disabled}
                                help="Square or wide, on a plain background. JPG, PNG or WebP, up to 4 MB."
                            />
                        </FormField>
                        <FormField id="brand_colour" label="Main colour" optional>
                            <ColourPicker
                                colours={props.colours}
                                value={form.data.brand_colour}
                                onChange={(v) => form.setData('brand_colour', v)}
                                error={errors.brand_colour}
                                disabled={disabled}
                            />
                        </FormField>
                        <FormField id="banner" label="Banner photo" optional error={errors.banner}>
                            <ImagePicker
                                label="Banner photo"
                                shape="wide"
                                current={store?.bannerUrl ?? null}
                                file={form.data.banner}
                                removed={form.data.remove_banner}
                                onPick={(f) => form.setData('banner', f)}
                                onRemove={(r) => form.setData('remove_banner', r)}
                                fallback={template.photo}
                                fallbackNote={`${template.label} photo`}
                                error={errors.banner}
                                disabled={disabled}
                                help="A wide photo of your shop or products, at least 1600 pixels wide. Without one, the template's photo is used."
                            />
                        </FormField>
                        <FormField
                            id="headline"
                            label="Headline"
                            optional
                            error={errors.headline}
                            help={`${form.data.headline.length} of 160 characters, e.g. "Fresh halal meat, ready when you are."`}
                        >
                            <Input
                                id="headline"
                                value={form.data.headline}
                                maxLength={160}
                                disabled={disabled}
                                aria-invalid={!!errors.headline}
                                onChange={(e) => form.setData('headline', e.target.value)}
                            />
                        </FormField>
                    </FormSection>

                    <FormSection title="Home page sections" description="Turn sections on or off and put them in the order you want.">
                        <SectionsEditor
                            value={form.data.sections}
                            options={props.sectionOptions}
                            onChange={(v) => form.setData('sections', v)}
                            disabled={disabled}
                        />
                        {sectionError && <p className="text-danger-foreground text-[13px]">{sectionError}</p>}
                    </FormSection>

                    <FormSection title="Orders" description="Where we email each new order. Your team also sees them on the Online orders board.">
                        <FormField
                            id="order_email"
                            label="Order email"
                            optional
                            error={errors.order_email}
                            help="Leave empty to rely on the board and portal notifications only."
                        >
                            <Input
                                id="order_email"
                                type="email"
                                className="sm:max-w-sm"
                                value={form.data.order_email}
                                maxLength={255}
                                disabled={disabled}
                                aria-invalid={!!errors.order_email}
                                onChange={(e) => form.setData('order_email', e.target.value)}
                            />
                        </FormField>
                    </FormSection>

                    <FormSection title="Payments" description="Click & collect for now. Card payments and local delivery come next.">
                        <div className="divide-border grid divide-y rounded-lg border">
                            <div className="flex items-center justify-between gap-4 p-3">
                                <div className="flex items-start gap-3">
                                    <CircleCheck className="text-success mt-0.5 size-5 shrink-0" />
                                    <div>
                                        <p className="text-sm font-medium">Pay at the shop when collecting</p>
                                        <p className="text-muted-foreground text-xs leading-5">
                                            The till takes the payment and the stock off when the customer collects.
                                        </p>
                                    </div>
                                </div>
                                <Badge variant="success">On</Badge>
                            </div>
                            <div className="flex items-center justify-between gap-4 p-3">
                                <div className="flex items-start gap-3">
                                    <CreditCard className="text-muted-foreground mt-0.5 size-5 shrink-0" />
                                    <div>
                                        <p className="text-muted-foreground text-sm font-medium">Card payments with DNA Payments</p>
                                        <p className="text-muted-foreground text-xs leading-5">
                                            Customers pay online; the money goes to your DNA account.
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Badge variant="neutral">Coming soon</Badge>
                                    <ToggleSwitch
                                        checked={false}
                                        disabled
                                        label="Card payments with DNA Payments (coming soon)"
                                        onCheckedChange={() => undefined}
                                    />
                                </div>
                            </div>
                        </div>
                    </FormSection>
                </FormCard>

                {canEdit && (form.isDirty || !store) && (
                    <StickyFormBar message={store ? 'You have unsaved changes.' : 'Save to create your store. Nobody sees it until you go live.'}>
                        {store && (
                            <Button type="button" variant="outline" disabled={form.processing} onClick={() => form.reset()}>
                                Discard changes
                            </Button>
                        )}
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            {store ? 'Save changes' : 'Create store'}
                        </Button>
                    </StickyFormBar>
                )}
            </form>
        </>
    );
}

/** OS-3: the online store builder (template, address, brand, sections, order email, payments, go live). */
export default function OnlineStoreBuilder(props: BuilderProps) {
    // A fresh form after each save, so "unsaved changes" compares with what is saved now (the switches keep edits).
    const s = props.store;
    const key = JSON.stringify(s && [s.slug, s.template, s.headline, s.brandColour, s.logoUrl, s.bannerUrl, s.sections, s.orderEmail]);

    return (
        <AppLayout>
            <Head title="Online store" />
            <Builder key={key} {...props} />
        </AppLayout>
    );
}

import { BannersEditor } from '@/components/app/online-store/banners-editor';
import { ColourPicker } from '@/components/app/online-store/colour-picker';
import { HomeContentFields } from '@/components/app/online-store/home-content-fields';
import { ImagePicker } from '@/components/app/online-store/image-picker';
import { OnlineStoreTabs } from '@/components/app/online-store/online-store-tabs';
import { SectionsEditor } from '@/components/app/online-store/sections-editor';
import { putSwitch, StatusPanel } from '@/components/app/online-store/status-panel';
import { TemplateCard } from '@/components/app/online-store/template-card';
import { ToggleSwitch } from '@/components/app/online-store/toggle-switch';
import {
    type BuilderProps,
    type FeaturedModeKey,
    type PickerProduct,
    type StoreSectionValue,
    type StoreSettings,
} from '@/components/app/online-store/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { FormCard, FormField, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { CircleCheck, CreditCard, ExternalLink, Eye, LoaderCircle, Power, Rocket, Store } from 'lucide-react';
import { useState } from 'react';

type BuilderForm = {
    slug: string;
    brand_colour: string;
    order_email: string;
    sections: StoreSectionValue[];
    logo: File | null;
    remove_logo: boolean;
    about_text: string;
    featured_mode: FeaturedModeKey;
    /** Hand-picked products with their names (sent as `featured_product_ids`). */
    picked: PickerProduct[];
    home_departments: string[];
};

function initial(store: StoreSettings, picked: PickerProduct[]): BuilderForm {
    return {
        slug: store.slug,
        brand_colour: store.brandColour ?? '',
        order_email: store.orderEmail ?? '',
        sections: store.sections,
        logo: null,
        remove_logo: false,
        about_text: store.aboutText ?? '',
        featured_mode: store.featuredMode,
        picked,
        home_departments: store.homeDepartments,
    };
}

const DESCRIPTION = 'Add your logo, choose what the home page shows and go live. Products, prices and stock come from your tills.';

/** No store row yet: Switch & Save switches the add-on on (and picks the look) before the business can build it. */
function NotSwitchedOn() {
    return (
        <>
            <PageHeader
                title="Online store"
                description={DESCRIPTION}
                status={<StatusBadge status="draft" label="Not switched on" />}
                tabs={<OnlineStoreTabs active="store" />}
            />
            <Card>
                <EmptyState
                    icon={Store}
                    tone="primary"
                    title="Your online store is not switched on yet"
                    body="Contact Switch & Save to switch it on. We set up your store's look, then you add your logo, collection shops and products here."
                />
            </Card>
        </>
    );
}

function Builder({ store, ...props }: BuilderProps & { store: StoreSettings }) {
    const { template, canEdit } = props;
    const form = useForm<BuilderForm>(initial(store, props.pickedProducts));
    const errors = form.errors as Record<string, string | undefined>;
    const [switching, setSwitching] = useState(false);
    const disabled = !canEdit || form.processing;
    const sectionError = Object.entries(errors).find(([key]) => key.startsWith('sections'))?.[1];

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // Empty lists are not sent in a form, so `*_sent` says the lists are there (and may be empty).
        form.transform(({ picked, ...data }) => ({
            ...data,
            featured_product_ids: picked.map((p) => p.id),
            featured_product_ids_sent: '1',
            home_departments_sent: '1',
        }));
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
                description={DESCRIPTION}
                status={
                    <StatusBadge
                        status={store.isLive ? 'live' : 'offline'}
                        label={store.isLive ? 'Live' : 'Offline'}
                        tones={{ live: 'success', offline: 'neutral' }}
                    />
                }
                tabs={<OnlineStoreTabs active="store" />}
                actions={
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
                    {template && (
                        <FormSection title="Look" description="The design of your store's pages.">
                            <TemplateCard template={template} />
                        </FormSection>
                    )}

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

                    <FormSection
                        title="Brand"
                        description="Your logo and main colour. The colour is used for buttons, links, prices and the header and footer."
                    >
                        <FormField id="logo" label="Logo" optional error={errors.logo}>
                            <ImagePicker
                                label="Logo"
                                current={store.logoUrl}
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
                    </FormSection>

                    <FormSection title="Banners" description="Up to 6 photos with a headline and a button, taking turns at the top of the home page.">
                        <BannersEditor props={props} disabled={!canEdit} />
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

                    <HomeContentFields
                        data={form.data}
                        set={(key, value) => form.setData(key, value as never)}
                        errors={errors}
                        props={props}
                        disabled={disabled}
                    />

                    <FormSection
                        title="Orders"
                        description="Where we email each new order. Customers' replies go here too (change that on the Emails tab). Your team also sees orders on the Online orders board."
                    >
                        <FormField
                            id="order_email"
                            label="Order email"
                            optional
                            error={errors.order_email}
                            help={
                                props.orderEmailFallback
                                    ? `Empty: orders go to ${props.orderEmailFallback} (your business email), and customers' replies too.`
                                    : 'Empty: no order emails. You see orders on the Online orders board and in your notifications.'
                            }
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

                {canEdit && form.isDirty && (
                    <StickyFormBar message="You have unsaved changes.">
                        <Button type="button" variant="outline" disabled={form.processing} onClick={() => form.reset()}>
                            Discard changes
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Save changes
                        </Button>
                    </StickyFormBar>
                )}
            </form>
        </>
    );
}

/**
 * OS-3, OS-8: the online store builder (address, brand, banners, sections, featured products, department rows, about
 * us, order email, payments, go live). The look is shown read only: Switch & Save sets it when switching the add-on
 * on. No store yet → "not switched on".
 */
export default function OnlineStoreBuilder(props: BuilderProps) {
    // A fresh form after each save, so "unsaved changes" compares with what is saved now (the switches and the banners,
    // which save on their own, keep edits).
    const s = props.store;
    const key = JSON.stringify(
        s && [
            s.slug,
            s.template,
            s.brandColour,
            s.logoUrl,
            s.sections,
            s.orderEmail,
            s.aboutText,
            s.featuredMode,
            s.featuredProductIds,
            s.homeDepartments,
        ],
    );

    return (
        <AppLayout>
            <Head title="Online store" />
            {s ? <Builder key={key} {...props} store={s} /> : <NotSwitchedOn />}
        </AppLayout>
    );
}

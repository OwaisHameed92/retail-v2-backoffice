import { AddressBrandFields } from '@/components/app/online-store/address-brand-fields';
import { AislesEditor } from '@/components/app/online-store/aisles-editor';
import { BannersEditor } from '@/components/app/online-store/banners-editor';
import { BuilderActions } from '@/components/app/online-store/builder-actions';
import { HomeContentFields } from '@/components/app/online-store/home-content-fields';
import { OnlineStoreTabs } from '@/components/app/online-store/online-store-tabs';
import { SectionsEditor } from '@/components/app/online-store/sections-editor';
import { StatusPanel } from '@/components/app/online-store/status-panel';
import {
    type BuilderProps,
    type FeaturedModeKey,
    type PickerProduct,
    type StoreSectionValue,
    type StoreSettings,
} from '@/components/app/online-store/types';
import { EmptyState } from '@/components/shared/empty-state';
import { FormCard, FormField, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle, Store } from 'lucide-react';

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
    const { canEdit } = props;
    const form = useForm<BuilderForm>(initial(store, props.pickedProducts));
    const errors = form.errors as Record<string, string | undefined>;
    const disabled = !canEdit || form.processing;
    const sectionError = Object.entries(errors).find(([key]) => key.startsWith('sections'))?.[1];
    const set = <K extends keyof BuilderForm>(key: K, value: BuilderForm[K]) => form.setData(key, value as never);

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
                actions={<BuilderActions store={store} canEdit={canEdit} dirty={form.isDirty} />}
            />

            <StatusPanel store={store} checklist={props.checklist} canEdit={canEdit} />

            {!canEdit && (
                <Alert variant="info">
                    <AlertDescription>You can look at the store settings. Only users who manage every shop can change them.</AlertDescription>
                </Alert>
            )}

            <form onSubmit={submit} className="grid gap-6">
                <FormCard>
                    <AddressBrandFields store={store} props={props} data={form.data} set={(key, value) => form.setData(key, value as never)} errors={errors} disabled={disabled} />

                    <FormSection title="Banners" description="Up to 6 photos with a headline and a button, taking turns at the top of the home page.">
                        <BannersEditor props={props} disabled={!canEdit} />
                    </FormSection>

                    <FormSection
                        title="Aisles"
                        description="How customers browse: your till departments or categories. Put them in order, rename them, add a photo or hide one. Products without one are still found by search."
                    >
                        <AislesEditor props={props} disabled={!canEdit} />
                    </FormSection>

                    <FormSection title="Home page sections" description="Turn sections on or off and put them in the order you want.">
                        <SectionsEditor value={form.data.sections} options={props.sectionOptions} onChange={(v) => set('sections', v)} disabled={disabled} />
                        {sectionError && <p className="text-danger-foreground text-[13px]">{sectionError}</p>}
                    </FormSection>

                    <HomeContentFields data={form.data} set={(key, value) => form.setData(key, value as never)} errors={errors} props={props} disabled={disabled} />

                    <FormSection
                        title="Orders"
                        description={
                            <>
                                Where we email each new order. Customers&rsquo; replies go here too (change that on the Emails tab). Ways to pay and
                                delivery are on the{' '}
                                <Link href={route('app.online-store.delivery')} className="text-primary font-medium hover:underline">
                                    Delivery &amp; payments
                                </Link>{' '}
                                tab.
                            </>
                        }
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
                                onChange={(e) => set('order_email', e.target.value)}
                            />
                        </FormField>
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
 * OS-3, OS-8, OS-11: the online store builder (address, brand, banners, aisles, sections, featured products, department
 * rows, about us, order email, go live). The look is shown read only: Switch & Save sets it when switching the add-on
 * on. No store yet → "not switched on". Pieces live in components/app/online-store (audit OSA-35).
 */
export default function OnlineStoreBuilder(props: BuilderProps) {
    // A fresh form after each save, so "unsaved changes" compares with what is saved now (the switches, the banners and
    // the aisles, which save on their own, keep edits).
    const s = props.store;
    const key = JSON.stringify(
        s && [s.slug, s.template, s.brandColour, s.logoUrl, s.sections, s.orderEmail, s.aboutText, s.featuredMode, s.featuredProductIds, s.homeDepartments],
    );

    return (
        <AppLayout>
            <Head title="Online store" />
            {s ? <Builder key={key} {...props} store={s} /> : <NotSwitchedOn />}
        </AppLayout>
    );
}

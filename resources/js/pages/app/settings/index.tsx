import { SettingField } from '@/components/app/settings/setting-field';
import { type ShopSettingsProps } from '@/components/app/settings/types';
import { FormCard, FormGrid, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Info, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useMemo } from 'react';

const EVERY_SHOP = 'every';

type Form = { shop: string | null; values: Record<string, string> };

/**
 * Module 4.9: the till settings a business shares with its tills (contract §10.3), for every shop or one shop.
 * Only what changed is sent; a blank value removes the setting here so the wider one applies again.
 */
export default function TillSettings(props: ShopSettingsProps) {
    return <SettingsForm key={props.shop?.id ?? EVERY_SHOP} {...props} />;
}

function SettingsForm({ shop, shops, canEveryShop, sections, values, inherited, overrides }: ShopSettingsProps) {
    const initial = useMemo(
        () => Object.fromEntries(sections.flatMap((s) => s.settings.map((d) => [d.key, values[d.key] ?? '']))) as Record<string, string>,
        [sections, values],
    );
    const form = useForm<Form>({ shop: shop?.id ?? null, values: initial });
    const { data, setData, processing, errors } = form;
    const changed = Object.keys(initial).filter((key) => data.values[key] !== initial[key]);
    const fieldErrors = errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((d) => ({ shop: d.shop, values: Object.fromEntries(changed.map((key) => [key, d.values[key] === '' ? null : d.values[key]])) }));
        form.put(route('app.settings.update'), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    const switchShop = (value: string) =>
        router.get(route('app.settings.index'), value === EVERY_SHOP ? {} : { shop: value }, { preserveScroll: false });

    const picker = (canEveryShop || shops.length > 1) && (
        <Select value={shop?.id ?? EVERY_SHOP} onValueChange={switchShop} disabled={changed.length > 0}>
            <SelectTrigger className="w-full sm:w-64" aria-label="Settings for">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {canEveryShop && <SelectItem value={EVERY_SHOP}>Every shop</SelectItem>}
                {shops.map((s) => (
                    <SelectItem key={s.id} value={s.id}>
                        {s.name} ({s.code})
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    return (
        <AppLayout>
            <Head title="Till settings" />
            <PageHeader
                title="Till settings"
                description="Receipts, opening hours, cash-up, refunds, age checks and loyalty. Your tills use a change within about 30 seconds of their next sync."
                actions={picker}
            />

            <Alert variant="info" className="mb-6">
                <Info aria-hidden />
                <AlertDescription>
                    {shop
                        ? `These settings are for ${shop.name} only and win over the every-shop settings on its tills. Leave one blank to use the every-shop setting.`
                        : 'These settings apply to every shop, unless a shop has its own. Leave one blank to use the till’s built-in setting.'}{' '}
                    Printers, scanners and other devices are set on each till.
                </AlertDescription>
            </Alert>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
                <nav aria-label="Setting sections" className="hidden lg:block">
                    <ul className="sticky top-20 grid gap-0.5 text-sm">
                        {sections.map((section) => (
                            <li key={section.id}>
                                <a
                                    href={`#section-${section.id}`}
                                    className="text-muted-foreground hover:bg-muted hover:text-foreground block rounded-md px-3 py-1.5 transition-colors"
                                >
                                    {section.title}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>

                <form onSubmit={submit} noValidate className="grid min-w-0 gap-6">
                    {sections.map((section) => (
                        <FormCard key={section.id}>
                            <div id={`section-${section.id}`} className="scroll-mt-24">
                                <FormSection title={section.title} description={section.description}>
                                    <FormGrid>
                                        {section.settings.map((definition) => (
                                            <SettingField
                                                key={definition.key}
                                                definition={definition}
                                                value={data.values[definition.key] ?? ''}
                                                onChange={(v) => setData('values', { ...data.values, [definition.key]: v })}
                                                inherited={inherited[definition.key]}
                                                overrides={shop ? undefined : overrides[definition.key]}
                                                isShop={shop !== null}
                                                error={fieldErrors[`values.${definition.key}`]}
                                            />
                                        ))}
                                    </FormGrid>
                                </FormSection>
                            </div>
                        </FormCard>
                    ))}

                    <StickyFormBar
                        message={
                            changed.length > 0
                                ? `${changed.length} unsaved ${changed.length === 1 ? 'change' : 'changes'} for ${shop ? shop.name : 'every shop'}.`
                                : 'No changes yet.'
                        }
                    >
                        <Button type="button" variant="outline" disabled={changed.length === 0 || processing} onClick={() => form.reset()}>
                            Discard
                        </Button>
                        <Button type="submit" disabled={changed.length === 0 || processing}>
                            {processing && <LoaderCircle className="animate-spin" aria-hidden />}
                            Save settings
                        </Button>
                    </StickyFormBar>
                </form>
            </div>
        </AppLayout>
    );
}

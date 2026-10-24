import { FormField, FormSection } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { ColourPicker } from './colour-picker';
import { ImagePicker } from './image-picker';
import { TemplateCard } from './template-card';
import { type BuilderProps, type StoreSettings } from './types';

interface Fields {
    slug: string;
    brand_colour: string;
    logo: File | null;
    remove_logo: boolean;
}

interface Props {
    store: StoreSettings;
    props: Omit<BuilderProps, 'store'>;
    data: Fields;
    set: <K extends keyof Fields>(key: K, value: Fields[K]) => void;
    errors: Record<string, string | undefined>;
    disabled: boolean;
}

/**
 * The store builder's look (read only), store address and brand (logo, main colour) sections (OS-3, OS-8; split out of
 * builder.tsx for OSA-35). OS-11 / OSA-6: a changed address keeps the old one working for 90 days.
 */
export function AddressBrandFields({ store, props, data, set, errors, disabled }: Props) {
    return (
        <>
            {props.template && (
                <FormSection title="Look" description="The design of your store's pages.">
                    <TemplateCard template={props.template} />
                </FormSection>
            )}

            <FormSection
                title="Store address"
                description="Lower-case letters, numbers and hyphens. If you change it, the old address keeps working for 90 days and then stops."
            >
                <FormField
                    id="slug"
                    label="Address"
                    error={errors.slug}
                    help={<span className="font-mono break-all">{props.addressPrefix + (data.slug.trim().toLowerCase() || 'your-shop')}</span>}
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
                            value={data.slug}
                            maxLength={40}
                            autoComplete="off"
                            spellCheck={false}
                            disabled={disabled}
                            aria-invalid={!!errors.slug}
                            aria-describedby={errors.slug ? 'slug-error' : 'slug-help'}
                            onChange={(e) => set('slug', e.target.value.toLowerCase().replace(/\s+/g, '-'))}
                        />
                    </div>
                </FormField>
            </FormSection>

            <FormSection title="Brand" description="Your logo and main colour. The colour is used for buttons, links, prices and the header and footer.">
                <FormField id="logo" label="Logo" optional error={errors.logo}>
                    <ImagePicker
                        label="Logo"
                        current={store.logoUrl}
                        file={data.logo}
                        removed={data.remove_logo}
                        onPick={(f) => set('logo', f)}
                        onRemove={(r) => set('remove_logo', r)}
                        error={errors.logo}
                        disabled={disabled}
                        help="Square or wide, on a plain background. JPG, PNG or WebP, up to 4 MB. We resize it and remove hidden photo data."
                    />
                </FormField>
                <FormField id="brand_colour" label="Main colour" optional>
                    <ColourPicker
                        colours={props.colours}
                        value={data.brand_colour}
                        onChange={(v) => set('brand_colour', v)}
                        error={errors.brand_colour}
                        disabled={disabled}
                    />
                </FormField>
            </FormSection>
        </>
    );
}

import { FormField, FormSection } from '@/components/shared/form-section';
import { Textarea } from '@/components/ui/textarea';
import { FeaturedEditor, HomeDepartmentsEditor } from './featured-editor';
import { type BuilderProps, type FeaturedModeKey, type PickerProduct } from './types';

export interface HomeContentData {
    about_text: string;
    featured_mode: FeaturedModeKey;
    picked: PickerProduct[];
    home_departments: string[];
}

/**
 * The store builder's home page content (OS-8): which featured products show, the departments with their own row,
 * and the "About us" text (audit OSA-29). Part of the builder form, saved with "Save changes".
 */
export function HomeContentFields({
    data,
    set,
    errors,
    props,
    disabled,
}: {
    data: HomeContentData;
    set: <K extends keyof HomeContentData>(key: K, value: HomeContentData[K]) => void;
    errors: Record<string, string | undefined>;
    props: Omit<BuilderProps, 'store'>;
    disabled: boolean;
}) {
    const firstError = (prefix: string) => Object.entries(errors).find(([key]) => key.startsWith(prefix))?.[1];

    return (
        <>
            <FormSection title="Featured products" description="Which products the home page's featured row shows.">
                <FeaturedEditor
                    props={props}
                    mode={data.featured_mode}
                    picked={data.picked}
                    onMode={(mode) => set('featured_mode', mode)}
                    onPicked={(picked) => set('picked', picked)}
                    disabled={disabled}
                    error={errors.featured_mode ?? firstError('featured_product_ids')}
                />
            </FormSection>

            <FormSection title="Rows by department" description="Departments with their own row of products (turn on “Rows by department” above).">
                <HomeDepartmentsEditor
                    departments={props.departments}
                    value={data.home_departments}
                    max={props.homeDepartmentsMax}
                    onChange={(ids) => set('home_departments', ids)}
                    disabled={disabled}
                    error={firstError('home_departments')}
                />
            </FormSection>

            <FormSection title="About us" description="A short note about your business for the home page's “About us” section.">
                <FormField
                    id="about_text"
                    label="About your business"
                    optional
                    error={errors.about_text}
                    help={`${data.about_text.length} of ${props.aboutMax} characters. Empty uses the text shown as the example.`}
                >
                    <Textarea
                        id="about_text"
                        rows={4}
                        value={data.about_text}
                        maxLength={props.aboutMax}
                        placeholder={props.aboutPlaceholder ?? undefined}
                        disabled={disabled}
                        aria-invalid={!!errors.about_text}
                        onChange={(e) => set('about_text', e.target.value)}
                    />
                </FormField>
            </FormSection>
        </>
    );
}

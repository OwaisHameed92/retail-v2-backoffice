import { FormSection } from '@/components/shared/form-section';
import { Label } from '@/components/ui/label';
import { ToggleSwitch } from './toggle-switch';

/**
 * IMG-2: the store builder's "Product photos" section, shown only while the till's pictures are switched on. The
 * public store shows the shop's own and Open Food Facts pictures; "Show found pictures" adds the ones the till found
 * by web search (and older ones of unknown source). Part of the builder form, saved with "Save changes".
 */
export function PhotoSettingsFields({ checked, onChange, disabled }: { checked: boolean; onChange: (checked: boolean) => void; disabled: boolean }) {
    return (
        <FormSection
            title="Product photos"
            description="Your store shows the photos you chose on the till and Open Food Facts photos (credited on the product page)."
        >
            <div className="flex items-start justify-between gap-4 sm:max-w-xl">
                <div className="flex min-w-0 flex-col gap-1">
                    <Label htmlFor="show_found_pictures">Show found pictures</Label>
                    <p id="show_found_pictures_help" className="text-muted-foreground text-[13px]">
                        Pictures the till found by web search, copyright unknown. Off by default.
                    </p>
                </div>
                <ToggleSwitch id="show_found_pictures" checked={checked} disabled={disabled} onCheckedChange={onChange} className="mt-0.5" />
            </div>
        </FormSection>
    );
}

import { cn } from '@/lib/utils';

interface StoreTemplatePickerProps {
    id: string;
    templates: { value: string; label: string }[];
    value: string;
    onChange: (value: string) => void;
    error?: string;
    hint?: string;
}

/**
 * OS-7: the online store's template (Fresh Market or Butcher's Block), chosen by Switch & Save when a plan switches the
 * add-on on for a business without a store (new tenant, Change plan). The business sets its logo, colours and products.
 */
export function StoreTemplatePicker({ id, templates, value, onChange, error, hint }: StoreTemplatePickerProps) {
    return (
        <fieldset className="grid gap-2" aria-describedby={`${id}-hint`}>
            <legend className="mb-2 text-sm font-medium">Online store template</legend>
            <div
                id={id}
                className="grid grid-cols-1 gap-2 sm:grid-cols-2"
                role="radiogroup"
                aria-label="Online store template"
                aria-invalid={!!error || undefined}
            >
                {templates.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={value === option.value}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'focus-visible:ring-ring rounded-lg border p-3 text-left text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none',
                            value === option.value ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted/60',
                            error && value === '' && 'border-destructive',
                        )}
                    >
                        {option.label}
                    </button>
                ))}
            </div>
            <p id={`${id}-hint`} className="text-muted-foreground text-sm">
                {hint ?? 'Chosen by Switch & Save; it can be changed later on the Billing tab. The business sets its logo, colours and products.'}
            </p>
            {error && <p className="text-destructive text-sm">{error}</p>}
        </fieldset>
    );
}

export default StoreTemplatePicker;

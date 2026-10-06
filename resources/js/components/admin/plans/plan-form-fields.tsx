import { FormField, FormSection as SharedFormSection } from '@/components/shared/form-section';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { currencySymbol, wideCurrencySymbol } from '@/lib/country';
import { cn } from '@/lib/utils';
import { type ComponentProps, type ReactNode } from 'react';

/** Label above, helper text and inline error below. Same as the shared FormField. */
export function Field(props: { id: string; label: string; help?: ReactNode; error?: string; className?: string; children: ReactNode }) {
    return <FormField {...props} />;
}

/** Text input with the currency symbol as a prefix. Keeps the value as a string; the server validates 2 dp. */
export function MoneyInput({ id, invalid, className, ...props }: ComponentProps<'input'> & { invalid?: boolean }) {
    return (
        <div className="relative">
            <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm" aria-hidden>
                {currencySymbol()}
            </span>
            <Input
                id={id}
                type="text"
                inputMode="decimal"
                autoComplete="off"
                pattern="^\d{1,5}(\.\d{1,2})?$"
                aria-invalid={invalid || undefined}
                aria-describedby={invalid ? `${id}-error` : `${id}-help`}
                className={cn(wideCurrencySymbol() ? 'pl-10' : 'pl-7', 'tabular-nums', className)}
                {...props}
            />
        </div>
    );
}

/** Whole-number input with a unit suffix ("days"). */
export function NumberInput({ id, suffix, invalid, className, ...props }: ComponentProps<'input'> & { suffix?: string; invalid?: boolean }) {
    return (
        <div className="relative">
            <Input
                id={id}
                type="number"
                inputMode="numeric"
                step={1}
                aria-invalid={invalid || undefined}
                aria-describedby={invalid ? `${id}-error` : `${id}-help`}
                className={cn('tabular-nums', suffix && 'pr-14', className)}
                {...props}
            />
            {suffix && (
                <span className="text-muted-foreground pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm" aria-hidden>
                    {suffix}
                </span>
            )}
        </div>
    );
}

/** A section of the form: title and description on the left, fields on the right. Same as the shared FormSection. */
export function FormSection({ title, description, children }: { title: string; description: ReactNode; children: ReactNode }) {
    return (
        <SharedFormSection title={title} description={description}>
            {children}
        </SharedFormSection>
    );
}

/** Checkbox with a label and helper line, for yes/no settings. */
export function CheckboxRow({
    id,
    checked,
    onChange,
    label,
    help,
    error,
}: {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    help: string;
    error?: string;
}) {
    return (
        <div className="hover:bg-subtle flex items-start gap-3 rounded-lg border p-3.5 transition-colors">
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(state) => onChange(state === true)}
                className="mt-0.5"
                aria-describedby={`${id}-help`}
            />
            <div className="grid gap-0.5">
                <label htmlFor={id} className="cursor-pointer text-sm font-medium">
                    {label}
                </label>
                <p id={`${id}-help`} className="text-muted-foreground text-sm">
                    {help}
                </p>
                {error && <p className="text-danger-foreground text-[13px]">{error}</p>}
            </div>
        </div>
    );
}

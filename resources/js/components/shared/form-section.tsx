import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * Long forms: a FormCard holds FormSections separated by hairlines. Each section has its title and one-line
 * description on the left and the fields on the right (stacked on phones).
 *
 *     <FormCard>
 *         <FormSection title="Business details" description="Sent to the till.">
 *             <FormGrid><FormField id="name" label="Business name" error={errors.name}><Input id="name" /></FormField></FormGrid>
 *         </FormSection>
 *     </FormCard>
 */
export function FormCard({ className, children }: { className?: string; children: ReactNode }) {
    return <Card className={cn('divide-y overflow-clip p-0', className)}>{children}</Card>;
}

interface FormSectionProps {
    title: string;
    description?: ReactNode;
    /** Stack title above fields even on desktop (short forms, dialogs). */
    stacked?: boolean;
    className?: string;
    children: ReactNode;
}

export function FormSection({ title, description, stacked = false, className, children }: FormSectionProps) {
    return (
        <section className={cn('grid gap-5 p-5 sm:p-6', !stacked && 'lg:grid-cols-[minmax(0,17rem)_minmax(0,1fr)] lg:gap-10', className)}>
            <div className="space-y-1">
                <h2 className="text-foreground text-[15px] leading-6 font-semibold tracking-tight">{title}</h2>
                {description && <p className="text-muted-foreground text-sm leading-6">{description}</p>}
            </div>
            <div className="grid min-w-0 content-start gap-5">{children}</div>
        </section>
    );
}

/** Two columns of short fields on desktop, one on phones. `columns={3}` for small numeric fields. */
export function FormGrid({ columns = 2, className, children }: { columns?: 1 | 2 | 3; className?: string; children: ReactNode }) {
    return (
        <div className={cn('grid gap-x-4 gap-y-5', columns === 2 && 'sm:grid-cols-2', columns === 3 && 'sm:grid-cols-3', className)}>{children}</div>
    );
}

interface FormFieldProps {
    id: string;
    label: ReactNode;
    /** Adds a muted "(optional)" after the label. Required is the default and not marked. */
    optional?: boolean;
    /** Helper text under the field. Hidden while an error shows. */
    help?: ReactNode;
    error?: string;
    /** Right of the label, e.g. a "Forgot password?" link. */
    labelAside?: ReactNode;
    className?: string;
    children: ReactNode;
}

/**
 * Label above, control, then helper text or the inline error. Give the control `aria-invalid` and
 * `aria-describedby={`${id}-error`}` / `${id}-help` so screen readers read them.
 */
export function FormField({ id, label, optional = false, help, error, labelAside, className, children }: FormFieldProps) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
            <div className="flex items-center justify-between gap-2">
                <Label htmlFor={id}>
                    {label}
                    {optional && <span className="text-muted-foreground font-normal"> (optional)</span>}
                </Label>
                {labelAside}
            </div>
            {children}
            {error ? (
                <p id={`${id}-error`} className="text-danger-foreground text-[13px] leading-5">
                    {error}
                </p>
            ) : (
                help && (
                    <p id={`${id}-help`} className="text-muted-foreground text-[13px] leading-5">
                        {help}
                    </p>
                )
            )}
        </div>
    );
}

export default FormSection;

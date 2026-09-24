import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type ComponentProps, type ReactNode } from 'react';

interface FieldProps {
    id: string;
    label: string;
    help?: ReactNode;
    error?: string;
    className?: string;
    children: ReactNode;
}

/** Label above, helper text and inline error below. */
export function Field({ id, label, help, error, className, children }: FieldProps) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {help && !error && (
                <p id={`${id}-help`} className="text-muted-foreground text-sm">
                    {help}
                </p>
            )}
            {error && (
                <p id={`${id}-error`} className="text-destructive text-sm">
                    {error}
                </p>
            )}
        </div>
    );
}

/** Text input with a £ prefix. Keeps the value as a string; the server validates 2 dp. */
export function MoneyInput({ id, invalid, className, ...props }: ComponentProps<'input'> & { invalid?: boolean }) {
    return (
        <div className="relative">
            <span className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm" aria-hidden>
                £
            </span>
            <Input
                id={id}
                type="text"
                inputMode="decimal"
                autoComplete="off"
                pattern="^\d{1,5}(\.\d{1,2})?$"
                aria-invalid={invalid || undefined}
                aria-describedby={invalid ? `${id}-error` : `${id}-help`}
                className={cn('pl-7 tabular-nums', invalid && 'border-destructive', className)}
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
                className={cn('tabular-nums', suffix && 'pr-14', invalid && 'border-destructive', className)}
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

/** A section of the form: title and one-line description on the left, fields on the right (stacked on phones). */
export function FormSection({ title, description, children }: { title: string; description: ReactNode; children: ReactNode }) {
    return (
        <section className="grid gap-4 p-6 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)] md:gap-8">
            <div className="space-y-1">
                <h2 className="text-base font-semibold">{title}</h2>
                <p className="text-muted-foreground text-sm">{description}</p>
            </div>
            <div className="grid min-w-0 gap-5">{children}</div>
        </section>
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
        <div className="flex items-start gap-3">
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
                {error && <p className="text-destructive text-sm">{error}</p>}
            </div>
        </div>
    );
}

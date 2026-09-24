import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type ReactNode, type TextareaHTMLAttributes } from 'react';

interface FieldProps {
    id: string;
    label: string;
    hint?: ReactNode;
    error?: string;
    optional?: boolean;
    className?: string;
    children: ReactNode;
}

/** Label above, control, helper text, inline error. */
export function Field({ id, label, hint, error, optional = false, className, children }: FieldProps) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
            <Label htmlFor={id}>
                {label}
                {optional && <span className="text-muted-foreground font-normal"> (optional)</span>}
            </Label>
            {children}
            {hint && !error && (
                <p id={`${id}-hint`} className="text-muted-foreground text-sm">
                    {hint}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return (
        <textarea
            className={cn(
                'border-input placeholder:text-muted-foreground flex min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none',
                'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50',
                'aria-invalid:border-destructive md:text-sm',
                className,
            )}
            {...props}
        />
    );
}

/** A titled group of fields inside a form card. */
export function FormSection({ title, description, children }: { title: string; description?: ReactNode; children: ReactNode }) {
    return (
        <section className="grid gap-6 border-t pt-6 first:border-t-0 first:pt-0 lg:grid-cols-[16rem_1fr] lg:gap-10">
            <div className="space-y-1">
                <h2 className="text-base font-semibold">{title}</h2>
                {description && <p className="text-muted-foreground text-sm">{description}</p>}
            </div>
            <div className="grid gap-5 sm:grid-cols-2">{children}</div>
        </section>
    );
}

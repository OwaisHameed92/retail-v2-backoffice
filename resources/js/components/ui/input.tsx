import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Shared look for every text control (Input, Textarea, SelectTrigger): white field, 1px input border,
 * brand-blue border + soft ring on focus, red border + ring when `aria-invalid`.
 */
export const fieldClasses = cn(
    'w-full min-w-0 rounded-lg border border-input bg-card text-sm text-foreground shadow-xs',
    'transition-[border-color,box-shadow] duration-150 ease-out outline-none',
    'placeholder:text-muted-foreground/80',
    'hover:border-border-strong',
    'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20',
    'aria-invalid:border-destructive aria-invalid:ring-destructive/15 aria-invalid:focus-visible:ring-[3px] aria-invalid:focus-visible:ring-destructive/20',
    'disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60',
    'dark:bg-background/40',
);

const Input = React.forwardRef<HTMLInputElement, React.ComponentProps<'input'>>(({ className, type, ...props }, ref) => {
    return (
        <input
            type={type}
            data-slot="input"
            className={cn(
                fieldClasses,
                'flex h-10 px-3 py-2 text-base md:text-sm',
                'file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground',
                '[&::-webkit-search-cancel-button]:cursor-pointer',
                className,
            )}
            ref={ref}
            {...props}
        />
    );
});

Input.displayName = 'Input';

export { Input };

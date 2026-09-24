import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Buttons. One primary (brand blue) action per view; everything else is outline, ghost or link.
 * Heights: sm 32px (tables, toolbars), default 36px, lg 40px (auth, empty states). Icons are 16px.
 */
const buttonVariants = cva(
    [
        'inline-flex shrink-0 items-center justify-center gap-2 rounded-lg text-sm font-medium whitespace-nowrap select-none',
        'transition-[color,background-color,border-color,box-shadow] duration-150 ease-out',
        'outline-none focus-visible:ring-[3px] focus-visible:ring-ring/35',
        'disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
        '[&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
    ],
    {
        variants: {
            variant: {
                default:
                    'bg-primary text-primary-foreground shadow-xs shadow-primary/20 hover:bg-primary-hover inset-shadow-[0_1px_0_0_rgb(255_255_255/0.12)]',
                destructive: 'bg-destructive text-destructive-foreground shadow-xs hover:bg-destructive/90 focus-visible:ring-destructive/35',
                outline: 'border border-input bg-card text-foreground shadow-xs hover:border-border-strong hover:bg-muted/70',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-muted',
                ghost: 'text-foreground/80 hover:bg-muted hover:text-foreground',
                link: 'h-auto px-0 text-primary underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-9 px-3.5',
                sm: 'h-8 gap-1.5 px-3 text-[13px]',
                lg: 'h-10 px-5',
                icon: 'size-9',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
    asChild?: boolean;
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(({ className, variant, size, asChild = false, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button';
    return <Comp className={cn(buttonVariants({ variant, size, className }))} ref={ref} {...props} />;
});
Button.displayName = 'Button';

export { Button, buttonVariants };

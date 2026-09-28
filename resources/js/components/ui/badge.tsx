import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Small labels: roles, audiences, "Test", "Main till", counts. For statuses use `StatusBadge` (shared), which
 * adds the dot and the status → tone mapping. Soft tones match StatusBadge.
 */
const badgeVariants = cva(
    'inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs leading-4 font-medium whitespace-nowrap transition-colors [&_svg]:size-3 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-primary text-primary-foreground',
                secondary: 'border-transparent bg-muted text-muted-foreground',
                destructive: 'border-transparent bg-destructive text-destructive-foreground',
                outline: 'border-border bg-card text-foreground/80',
                success: 'border-success/20 bg-success-soft text-success-foreground',
                warning: 'border-warning/25 bg-warning-soft text-warning-foreground',
                danger: 'border-danger/20 bg-danger-soft text-danger-foreground',
                info: 'border-info/20 bg-info-soft text-info-foreground',
                violet: 'border-violet/20 bg-violet-soft text-violet-foreground',
                neutral: 'border-border bg-muted text-muted-foreground',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

export interface BadgeProps extends React.HTMLAttributes<HTMLDivElement>, VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
    return <div className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };

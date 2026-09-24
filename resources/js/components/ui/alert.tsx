import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

const alertVariants = cva(
    'relative w-full rounded-xl border p-4 text-sm [&>svg~*]:pl-7 [&>svg+div]:translate-y-[-3px] [&>svg]:absolute [&>svg]:top-4 [&>svg]:left-4 [&>svg]:text-foreground',
    {
        variants: {
            variant: {
                default: 'bg-card text-foreground shadow-card',
                info: 'border-primary/20 bg-info-soft text-info-foreground [&>svg]:text-info',
                success: 'border-success/25 bg-success-soft text-success-foreground [&>svg]:text-success',
                warning: 'border-warning/30 bg-warning-soft text-warning-foreground [&>svg]:text-warning',
                destructive: 'border-destructive/25 bg-danger-soft text-danger-foreground [&>svg]:text-destructive',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

const Alert = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement> & VariantProps<typeof alertVariants>>(
    ({ className, variant, ...props }, ref) => <div ref={ref} role="alert" className={cn(alertVariants({ variant }), className)} {...props} />,
);
Alert.displayName = 'Alert';

const AlertTitle = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLHeadingElement>>(({ className, ...props }, ref) => (
    <h5 ref={ref} className={cn('mb-1 font-medium leading-none tracking-tight', className)} {...props} />
));
AlertTitle.displayName = 'AlertTitle';

const AlertDescription = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLParagraphElement>>(({ className, ...props }, ref) => (
    <div ref={ref} className={cn('text-sm [&_p]:leading-relaxed', className)} {...props} />
));
AlertDescription.displayName = 'AlertDescription';

export { Alert, AlertDescription, AlertTitle };

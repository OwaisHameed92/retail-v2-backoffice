import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import { Check } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

const Checkbox = React.forwardRef<React.ElementRef<typeof CheckboxPrimitive.Root>, React.ComponentPropsWithoutRef<typeof CheckboxPrimitive.Root>>(
    ({ className, ...props }, ref) => (
        <CheckboxPrimitive.Root
            ref={ref}
            className={cn(
                'peer size-[18px] shrink-0 rounded-[5px] border border-border-strong bg-card shadow-xs transition-colors duration-150 outline-none hover:border-primary/60 focus-visible:ring-[3px] focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground dark:bg-background/40',
                className,
            )}
            {...props}
        >
            <CheckboxPrimitive.Indicator className={cn('flex items-center justify-center text-current')}>
                <Check className="size-3 stroke-[3.5]" />
            </CheckboxPrimitive.Indicator>
        </CheckboxPrimitive.Root>
    ),
);
Checkbox.displayName = CheckboxPrimitive.Root.displayName;

export { Checkbox };

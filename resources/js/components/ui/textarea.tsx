import * as React from 'react';

import { fieldClasses } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/** Multi-line text field with the same border, focus ring and error state as Input. */
const Textarea = React.forwardRef<HTMLTextAreaElement, React.ComponentProps<'textarea'>>(({ className, ...props }, ref) => (
    <textarea ref={ref} data-slot="textarea" className={cn(fieldClasses, 'flex min-h-20 px-3 py-2 text-base leading-relaxed md:text-sm', className)} {...props} />
));
Textarea.displayName = 'Textarea';

export { Textarea };

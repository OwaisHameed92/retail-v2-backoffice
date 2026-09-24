import { cn } from '@/lib/utils';
import { HTMLAttributes } from 'react';

export default function InputError({ message, className = '', ...props }: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return message ? (
        <p {...props} className={cn('text-danger-foreground text-[13px] leading-5', className)}>
            {message}
        </p>
    ) : null;
}

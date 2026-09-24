import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ComponentProps } from 'react';

type LinkProps = ComponentProps<typeof Link>;

export default function TextLink({ className = '', children, ...props }: LinkProps) {
    return (
        <Link className={cn('text-primary font-medium underline-offset-4 transition-colors duration-150 hover:underline', className)} {...props}>
            {children}
        </Link>
    );
}

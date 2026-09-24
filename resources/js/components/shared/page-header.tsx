import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    description?: ReactNode;
    /** Buttons shown on the right (below the title on phones). */
    actions?: ReactNode;
    className?: string;
}

export function PageHeader({ title, description, actions, className }: PageHeaderProps) {
    return (
        <div className={cn('flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between', className)}>
            <div className="min-w-0 space-y-1">
                <h1 className="truncate text-xl font-semibold tracking-tight">{title}</h1>
                {description && <p className="text-sm text-muted-foreground">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2 sm:shrink-0">{actions}</div>}
        </div>
    );
}

export default PageHeader;

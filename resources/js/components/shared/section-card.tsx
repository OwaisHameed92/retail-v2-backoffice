import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface SectionCardProps {
    title?: ReactNode;
    description?: ReactNode;
    /** Buttons or links on the right of the header ("Edit", "View all"). */
    actions?: ReactNode;
    /** Bottom bar on a subtle fill, e.g. "View all activity" or form buttons. */
    footer?: ReactNode;
    /** Content touches the card edges (tables, lists with their own dividers). */
    flush?: boolean;
    className?: string;
    contentClassName?: string;
    id?: string;
    children: ReactNode;
}

/**
 * The standard content block: a Card with a header (title, description, actions), body and optional footer.
 * Detail pages, dashboards and settings are built from these.
 */
export function SectionCard({ title, description, actions, footer, flush = false, className, contentClassName, id, children }: SectionCardProps) {
    const hasHeader = title || description || actions;

    return (
        <Card id={id} className={cn('flex flex-col overflow-clip', className)}>
            {hasHeader && (
                <div className={cn('flex flex-col gap-3 px-5 pt-5 sm:flex-row sm:items-start sm:justify-between sm:px-6', flush ? 'pb-4' : 'pb-0')}>
                    <div className="min-w-0 space-y-1">
                        {title && <h2 className="text-foreground text-[15px] leading-6 font-semibold tracking-tight">{title}</h2>}
                        {description && <div className="text-muted-foreground text-sm">{description}</div>}
                    </div>
                    {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
                </div>
            )}
            <div className={cn(flush ? (hasHeader ? 'border-t' : '') : 'px-5 py-5 sm:px-6', contentClassName)}>{children}</div>
            {footer && <div className="bg-subtle flex items-center gap-2 border-t px-5 py-3 text-sm sm:px-6">{footer}</div>}
        </Card>
    );
}

export default SectionCard;

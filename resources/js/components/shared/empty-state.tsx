import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

interface EmptyStateProps {
    icon?: LucideIcon;
    title: string;
    body?: ReactNode;
    /** Usually one button, e.g. "Add product". */
    action?: ReactNode;
    className?: string;
}

export function EmptyState({ icon: Icon, title, body, action, className }: EmptyStateProps) {
    return (
        <div className={cn('flex flex-col items-center justify-center gap-3 px-4 py-12 text-center', className)}>
            {Icon && (
                <div className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <Icon className="size-5" aria-hidden />
                </div>
            )}
            <div className="max-w-sm space-y-1">
                <p className="text-sm font-medium">{title}</p>
                {body && <p className="text-sm text-muted-foreground">{body}</p>}
            </div>
            {action && <div className="pt-1">{action}</div>}
        </div>
    );
}

export default EmptyState;

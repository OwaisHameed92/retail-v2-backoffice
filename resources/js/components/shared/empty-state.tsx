import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

type EmptyTone = 'primary' | 'neutral' | 'success' | 'warning' | 'danger';

interface EmptyStateProps {
    icon?: LucideIcon;
    title: string;
    body?: ReactNode;
    /** Usually one button, e.g. "Add product". A second, secondary action can sit next to it. */
    action?: ReactNode;
    /** Icon tint. Primary (brand blue) invites a first action; neutral for "nothing matches". */
    tone?: EmptyTone;
    /** sm for inside cards and tabs, md (default) for whole lists and pages. */
    size?: 'sm' | 'md';
    /** Dashed outline panel, for empty areas that sit directly on the canvas. */
    bordered?: boolean;
    className?: string;
}

const toneClasses: Record<EmptyTone, string> = {
    primary: 'bg-primary-soft text-primary ring-primary/10',
    neutral: 'bg-muted text-muted-foreground ring-border',
    success: 'bg-success-soft text-success-foreground ring-success/15',
    warning: 'bg-warning-soft text-warning-foreground ring-warning/20',
    danger: 'bg-danger-soft text-danger-foreground ring-destructive/15',
};

/**
 * Empty, "nothing matches" and "coming soon" states: lucide icon in a tinted circle with a soft halo, a short
 * title, one or two sentences and the action that fixes it.
 */
export function EmptyState({ icon: Icon, title, body, action, tone, size = 'md', bordered = false, className }: EmptyStateProps) {
    const resolvedTone: EmptyTone = tone ?? (action ? 'primary' : 'neutral');

    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center text-center',
                size === 'sm' ? 'gap-3 px-4 py-8' : 'gap-4 px-6 py-14',
                bordered && 'bg-card/50 border-border-strong rounded-xl border border-dashed',
                className,
            )}
        >
            {Icon && (
                <div className={cn('relative flex items-center justify-center', size === 'sm' ? 'size-12' : 'size-16')} aria-hidden>
                    <span className={cn('absolute inset-0 rounded-full opacity-45', toneClasses[resolvedTone])} />
                    <span
                        className={cn(
                            'relative flex items-center justify-center rounded-full ring-1',
                            size === 'sm' ? 'size-10' : 'size-12',
                            toneClasses[resolvedTone],
                        )}
                    >
                        <Icon className={size === 'sm' ? 'size-[18px]' : 'size-5'} />
                    </span>
                </div>
            )}
            <div className="max-w-md space-y-1.5">
                <p className={cn('text-foreground font-semibold tracking-tight', size === 'sm' ? 'text-sm' : 'text-base')}>{title}</p>
                {body && <div className="text-muted-foreground text-sm leading-6 text-balance">{body}</div>}
            </div>
            {action && <div className="flex flex-wrap items-center justify-center gap-2 pt-1">{action}</div>}
        </div>
    );
}

export default EmptyState;

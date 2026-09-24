import { cn } from '@/lib/utils';

export type StatusTone = 'success' | 'warning' | 'danger' | 'neutral' | 'info';

export type StatusToneMap = Record<string, StatusTone>;

const toneClasses: Record<StatusTone, string> = {
    success: 'bg-success-soft text-success-foreground ring-success/25',
    warning: 'bg-warning-soft text-warning-foreground ring-warning/30',
    danger: 'bg-danger-soft text-destructive ring-destructive/20',
    info: 'bg-info-soft text-accent-foreground ring-primary/20',
    neutral: 'bg-muted text-muted-foreground ring-border',
};

const dotClasses: Record<StatusTone, string> = {
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-destructive',
    info: 'bg-primary',
    neutral: 'bg-muted-foreground/60',
};

/** Sensible defaults for statuses used across the product. Override or extend with the `tones` prop. */
export const defaultStatusTones: StatusToneMap = {
    active: 'success',
    ok: 'success',
    paid: 'success',
    completed: 'success',
    online: 'success',
    approved: 'success',
    trial: 'info',
    new: 'info',
    issued: 'neutral',
    contacted: 'info',
    grace: 'warning',
    pending: 'warning',
    overdue: 'warning',
    due: 'warning',
    expiring: 'warning',
    suspended: 'danger',
    revoked: 'danger',
    expired: 'danger',
    failed: 'danger',
    rejected: 'danger',
    offline: 'danger',
    cancelled: 'neutral',
    draft: 'neutral',
    inactive: 'neutral',
};

/** "gracePeriod" / "grace_period" → "Grace period". */
export function statusLabel(status: string): string {
    const words = status
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/[_-]+/g, ' ')
        .trim()
        .toLowerCase();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

interface StatusBadgeProps {
    status: string;
    /** Extra or overriding status → tone entries. */
    tones?: StatusToneMap;
    /** Text to show instead of the sentence-cased status. */
    label?: string;
    className?: string;
}

export function StatusBadge({ status, tones, label, className }: StatusBadgeProps) {
    const tone: StatusTone = tones?.[status] ?? defaultStatusTones[status] ?? 'neutral';

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
                toneClasses[tone],
                className,
            )}
        >
            <span className={cn('size-1.5 rounded-full', dotClasses[tone])} aria-hidden />
            {label ?? statusLabel(status)}
        </span>
    );
}

export default StatusBadge;

import { cn } from '@/lib/utils';

export type StatusTone = 'success' | 'warning' | 'danger' | 'neutral' | 'info';

export type StatusToneMap = Record<string, StatusTone>;

/** Soft pill per tone. Also used by Badge's soft variants, so statuses and labels match everywhere. */
export const statusToneClasses: Record<StatusTone, string> = {
    success: 'bg-success-soft text-success-foreground ring-success/20',
    warning: 'bg-warning-soft text-warning-foreground ring-warning/30',
    danger: 'bg-danger-soft text-danger-foreground ring-destructive/20',
    info: 'bg-info-soft text-info-foreground ring-primary/20',
    neutral: 'bg-muted text-muted-foreground ring-border-strong/70',
};

export const statusDotClasses: Record<StatusTone, string> = {
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-destructive',
    info: 'bg-info',
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
    sent: 'success',
    delivered: 'success',
    trial: 'info',
    new: 'info',
    issued: 'neutral',
    contacted: 'info',
    queued: 'neutral',
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
    archived: 'neutral',
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

/** The tone a status maps to, after `tones` overrides. */
export function statusTone(status: string, tones?: StatusToneMap): StatusTone {
    return tones?.[status] ?? defaultStatusTones[status] ?? 'neutral';
}

interface StatusBadgeProps {
    status: string;
    /** Extra or overriding status → tone entries. */
    tones?: StatusToneMap;
    /** Text to show instead of the sentence-cased status. */
    label?: string;
    /** Force a tone regardless of the status value. */
    tone?: StatusTone;
    className?: string;
}

/** Status pill: coloured dot + sentence-case label on a soft tint. Every status in the product uses it. */
export function StatusBadge({ status, tones, label, tone: forcedTone, className }: StatusBadgeProps) {
    const tone: StatusTone = forcedTone ?? statusTone(status, tones);

    return (
        <span
            className={cn(
                'inline-flex h-[22px] items-center gap-1.5 rounded-full px-2 text-xs leading-none font-medium whitespace-nowrap ring-1 ring-inset',
                statusToneClasses[tone],
                className,
            )}
        >
            <span className={cn('size-1.5 shrink-0 rounded-full', statusDotClasses[tone])} aria-hidden />
            {label ?? statusLabel(status)}
        </span>
    );
}

export default StatusBadge;

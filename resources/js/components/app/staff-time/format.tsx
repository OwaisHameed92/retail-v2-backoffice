import { StatusPill } from '@/components/shared/status-badge';
import { dateFormat, dateLocale, zonedDateFormat } from '@/lib/country';
import { cn } from '@/lib/utils';
import { type ShiftFlag, type ShiftStatus } from './types';

export { formatDay } from '@/components/app/pricing/format';
export { money, number } from '@/components/shared/trading/format';

const time = () => zonedDateFormat(dateLocale(), { hour: '2-digit', minute: '2-digit' });
const dayShort = () => dateFormat({ weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });

/** "14:05" shop time from an ISO UTC instant; null → "—". */
export function clockTime(iso: string | null): string {
    return iso ? time().format(new Date(iso)) : '—';
}

const dayKey = () => zonedDateFormat('en-CA');

/** The shop-time `Y-m-d` of an ISO UTC instant. */
export function shopDay(iso: string): string {
    return dayKey().format(new Date(iso));
}

/** "Mon 5 Oct" from a `Y-m-d` day. */
export function shortDay(day: string): string {
    return dayShort().format(new Date(`${day}T12:00:00Z`));
}

/** 450 → "7h 30m"; 0 → "0h". */
export function duration(minutes: number): string {
    const sign = minutes < 0 ? '−' : '';
    const m = Math.abs(minutes);
    const h = Math.floor(m / 60);
    const rest = m % 60;

    return `${sign}${h}h${rest ? ` ${String(rest).padStart(2, '0')}m` : ''}`;
}

/** 450 → "7.50" decimal hours (as payroll wants them). */
export function decimalHours(minutes: number): string {
    return (minutes / 60).toFixed(2);
}

/** Hours with the decimal underneath: "7h 30m" / "7.50 h". */
export function Hours({ minutes, muted = false, className }: { minutes: number; muted?: boolean; className?: string }) {
    return (
        <span className={cn('grid text-right leading-5 tabular-nums', className)}>
            <span className={cn(muted && 'text-muted-foreground')}>{duration(minutes)}</span>
            <span className="text-muted-foreground text-xs">{decimalHours(minutes)} h</span>
        </span>
    );
}

/** Worked minus planned: green over, amber under, plain when equal. */
export function Difference({ minutes }: { minutes: number }) {
    if (minutes === 0) {
        return <span className="text-muted-foreground tabular-nums">±0h</span>;
    }

    return (
        <span className={cn('tabular-nums', minutes > 0 ? 'text-success-foreground' : 'text-warning-foreground')}>
            {minutes > 0 ? '+' : ''}
            {duration(minutes)}
        </span>
    );
}

const STATUS: Record<ShiftStatus, { label: string; tone: 'success' | 'info' | 'danger' | 'warning' }> = {
    complete: { label: 'Complete', tone: 'success' },
    open: { label: 'On shift', tone: 'info' },
    missingOut: { label: 'No clock-out', tone: 'danger' },
    missingIn: { label: 'No clock-in', tone: 'warning' },
};

export function ShiftStatusPill({ status }: { status: ShiftStatus }) {
    return <StatusPill tone={STATUS[status].tone}>{STATUS[status].label}</StatusPill>;
}

export const FLAG_LABELS: Record<ShiftFlag, string> = {
    breakNotEnded: 'Break not ended',
    overMaxShift: 'Over max shift hours',
};

export const ROUNDING_OPTIONS = [
    { value: '0', label: 'Exact minutes' },
    { value: '5', label: 'Nearest 5 min' },
    { value: '10', label: 'Nearest 10 min' },
    { value: '15', label: 'Nearest 15 min' },
];

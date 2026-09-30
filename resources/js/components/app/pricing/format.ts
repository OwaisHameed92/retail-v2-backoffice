import { type StatusToneMap } from '@/components/shared/status-badge';

const dateTime = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/London',
});
const date = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Europe/London' });

/** "5 Oct 2026, 10:00" in London time, from an ISO UTC string. */
export function formatDateTime(iso: string | null): string {
    return iso ? dateTime.format(new Date(iso)) : '—';
}

/** "5 Oct 2026" from a `Y-m-d` date (a calendar day, no time zone shift). */
export function formatDay(day: string | null): string {
    return day ? date.format(new Date(`${day}T12:00:00Z`)) : '—';
}

export function pounds(value: string | null | undefined): string {
    return value === null || value === undefined || value === '' ? '—' : `£${Number(value).toFixed(2)}`;
}

/** Difference of a shop price against the business price, e.g. "−£0.10". */
export function difference(price: string, business: string): string | null {
    const diff = Math.round((Number(price) - Number(business)) * 100) / 100;
    if (!Number.isFinite(diff) || diff === 0) {
        return null;
    }
    return `${diff > 0 ? '+' : '−'}£${Math.abs(diff).toFixed(2)}`;
}

export const PRICE_TONES: StatusToneMap = { live: 'success', scheduled: 'info', ended: 'neutral', cancelled: 'neutral' };

export const OFFER_TONES: StatusToneMap = { live: 'success', scheduled: 'info', ended: 'neutral' };

export const changedAtLabel = (changedAt: 'shop' | 'portal') => (changedAt === 'shop' ? 'Shop till' : 'Portal');

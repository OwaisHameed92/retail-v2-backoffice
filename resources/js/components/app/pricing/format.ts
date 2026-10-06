import { type StatusToneMap } from '@/components/shared/status-badge';
import { dateLocale, formatMoneyAsGiven, zonedDateFormat } from '@/lib/country';

const dateTime = () =>
    zonedDateFormat(dateLocale(), {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
const date = () => zonedDateFormat(dateLocale(), { day: 'numeric', month: 'short', year: 'numeric' });

/** "5 Oct 2026, 10:00" in shop time, from an ISO UTC string. */
export function formatDateTime(iso: string | null): string {
    return iso ? dateTime().format(new Date(iso)) : '—';
}

/** "5 Oct 2026" from a `Y-m-d` date (a calendar day, no time zone shift). */
export function formatDay(day: string | null): string {
    return day ? date().format(new Date(`${day}T12:00:00Z`)) : '—';
}

/** GB "£1.50" (2 dp, as the price pages always read), PK "Rs 2". */
export function pounds(value: string | null | undefined): string {
    return value === null || value === undefined || value === '' ? '—' : formatMoneyAsGiven(Number(value).toFixed(2));
}

/** Difference of a shop price against the business price, e.g. "−£0.10". */
export function difference(price: string, business: string): string | null {
    const diff = Math.round((Number(price) - Number(business)) * 100) / 100;
    if (!Number.isFinite(diff) || diff === 0) {
        return null;
    }
    return `${diff > 0 ? '+' : '−'}${formatMoneyAsGiven(Math.abs(diff).toFixed(2))}`;
}

export const PRICE_TONES: StatusToneMap = { live: 'success', scheduled: 'info', ended: 'neutral', cancelled: 'neutral' };

export const OFFER_TONES: StatusToneMap = { live: 'success', scheduled: 'info', ended: 'neutral' };

export const changedAtLabel = (changedAt: 'shop' | 'portal') => (changedAt === 'shop' ? 'Shop till' : 'Portal');

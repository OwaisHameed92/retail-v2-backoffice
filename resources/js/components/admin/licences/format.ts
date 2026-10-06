import { type LicenceStatus } from '@/components/admin/licences/types';
import { type StatusToneMap } from '@/components/shared/status-badge';
import { dateFormat, relativeTimeFormat, zonedDateFormat } from '@/lib/country';

export { formatDate, formatDateTimeShort, plural } from '@/components/admin/tenants/format';

export const licenceStatusTones: StatusToneMap = {
    issued: 'neutral',
    trial: 'info',
    active: 'success',
    grace: 'warning',
    expired: 'danger',
    suspended: 'danger',
    revoked: 'danger',
};

export const licenceStatusLabels: Record<LicenceStatus, string> = {
    issued: 'Issued',
    trial: 'Trial',
    active: 'Active',
    grace: 'Grace',
    expired: 'Expired',
    suspended: 'Suspended',
    revoked: 'Revoked',
};

/** One line per status: what it means for the till. */
export const licenceStatusHelp: Record<LicenceStatus, string> = {
    issued: 'Key issued, not yet entered on a till.',
    trial: 'Free trial, started on first activation.',
    active: 'Paid and trading.',
    grace: 'Past its end date. The till still trades for the grace days.',
    expired: 'The till is locked until the licence is renewed.',
    suspended: 'Locked. The till stops trading at its next check-in.',
    revoked: 'Permanently cancelled. The key never works again.',
};

const relative = () => relativeTimeFormat({ numeric: 'auto' });

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['week', 7 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
];

/** "3 hours ago", "in 5 days", "just now". */
export function formatRelative(iso: string | null | undefined, fallback = 'Never', now: Date = new Date()): string {
    if (!iso) {
        return fallback;
    }
    const seconds = Math.round((new Date(iso).getTime() - now.getTime()) / 1000);

    if (Math.abs(seconds) < 60) {
        return 'just now';
    }

    for (const [unit, size] of UNITS) {
        if (Math.abs(seconds) >= size) {
            return relative().format(Math.round(seconds / size), unit);
        }
    }

    return relative().format(Math.round(seconds / 60), 'minute');
}

/** Whole days from now until the date (negative when past). */
export function daysUntil(iso: string | null | undefined, now: Date = new Date()): number | null {
    if (!iso) {
        return null;
    }

    return Math.ceil((new Date(iso).getTime() - now.getTime()) / (24 * 3600 * 1000));
}

const shopParts = () => zonedDateFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' });

/** Today's date in shop time as "YYYY-MM-DD". */
export function shopToday(now: Date = new Date()): string {
    return shopParts().format(now);
}

/**
 * Preview of RenewalTerm::expiryFrom: from the current end when it is still ahead, else today; +1 month or
 * +1 year without overflow (31 Jan + 1 month = 28/29 Feb). Returns the shop-time date "YYYY-MM-DD".
 */
export function renewalPreview(term: 'month' | 'year', currentEnd: string | null, now: Date = new Date()): string {
    const base = currentEnd && new Date(currentEnd) > now ? new Date(currentEnd) : now;
    const [y, m, d] = shopParts().format(base).split('-').map(Number);
    const months = term === 'month' ? 1 : 12;
    const targetMonthIndex = m - 1 + months;
    const year = y + Math.floor(targetMonthIndex / 12);
    const month = (targetMonthIndex % 12) + 1;
    const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const day = Math.min(d, lastDay);

    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

const longDate = () => dateFormat({ timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' });

/** "2026-10-24" → "24 Oct 2026" (a calendar date, no time zone shift). */
export function formatCalendarDate(ymd: string): string {
    const [y, m, d] = ymd.split('-').map(Number);

    return longDate().format(new Date(Date.UTC(y, m - 1, d)));
}

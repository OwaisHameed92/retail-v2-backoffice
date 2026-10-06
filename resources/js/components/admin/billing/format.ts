import { type InvoiceStatus } from '@/components/admin/billing/types';
import { type StatusToneMap } from '@/components/shared/status-badge';
import { dateFormat, zonedDateFormat } from '@/lib/country';

export { formatDate, formatDateTimeShort, plural } from '@/components/admin/tenants/format';

export const invoiceStatusTones: StatusToneMap = {
    draft: 'neutral',
    issued: 'info',
    partiallyPaid: 'warning',
    paid: 'success',
    overdue: 'danger',
    void: 'neutral',
};

export const invoiceStatusLabels: Record<InvoiceStatus, string> = {
    draft: 'Draft',
    issued: 'Issued',
    partiallyPaid: 'Partly paid',
    paid: 'Paid',
    overdue: 'Overdue',
    void: 'Void',
};

export const invoiceStatusHelp: Record<InvoiceStatus, string> = {
    draft: 'Not sent yet. Edit it, then issue it to give it a number and email it.',
    issued: 'Sent and waiting for payment.',
    partiallyPaid: 'Part of it is paid; the rest is still owed.',
    paid: 'Paid in full. Its tills are renewed to the end of the period.',
    overdue: 'Past its due date and not paid.',
    void: 'Cancelled. Nothing is owed on it.',
};

const calendar = () => dateFormat({ timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' });

/** "2026-10-24" → "24 Oct 2026" (a calendar date: no time zone shift). */
export function formatDay(ymd: string | null | undefined, fallback = '—'): string {
    if (!ymd) {
        return fallback;
    }
    const [y, m, d] = ymd.split('-').map(Number);

    return calendar().format(new Date(Date.UTC(y, m - 1, d)));
}

const shopParts = () => zonedDateFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' });

/** Today in shop time, "YYYY-MM-DD". */
export function shopToday(now: Date = new Date()): string {
    return shopParts().format(now);
}

/** Whole calendar days from today (shop time) to the date; negative when past. */
export function daysFromToday(ymd: string | null | undefined, now: Date = new Date()): number | null {
    if (!ymd) {
        return null;
    }
    const [ty, tm, td] = shopToday(now).split('-').map(Number);
    const [y, m, d] = ymd.split('-').map(Number);

    return Math.round((Date.UTC(y, m - 1, d) - Date.UTC(ty, tm - 1, td)) / 86_400_000);
}

/** "due today", "due in 3 days", "due tomorrow", "4 days overdue". */
export function dueLabel(ymd: string | null | undefined, open: boolean): string | null {
    const days = daysFromToday(ymd);
    if (days === null || !open) {
        return null;
    }
    if (days === 0) {
        return 'Due today';
    }
    if (days === 1) {
        return 'Due tomorrow';
    }
    if (days > 1) {
        return `Due in ${days} days`;
    }

    return `${-days} ${days === -1 ? 'day' : 'days'} overdue`;
}

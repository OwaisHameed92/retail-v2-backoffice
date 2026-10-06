import { zonedDateFormat } from '@/lib/country';

const gbp = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' });

const dateFormat = () => zonedDateFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

/** "1234.5" → "£1,234.50". Display only; amounts stay strings everywhere else. */
export function formatMoney(amount: string | null | undefined): string {
    if (amount === null || amount === undefined || amount.trim() === '' || Number.isNaN(Number(amount))) {
        return '£0.00';
    }

    return gbp.format(Number(amount));
}

/** "7 days", "1 day", "No trial" style labels. */
export function formatDays(days: number, zero = 'None'): string {
    if (days === 0) {
        return zero;
    }

    return `${days} ${days === 1 ? 'day' : 'days'}`;
}

/** UTC ISO → "24 Sept 2026" in the profile's time zone. */
export function formatDate(iso: string | null): string {
    return iso ? dateFormat().format(new Date(iso)) : '—';
}

/** "Standard Plus!" → "standard-plus". Mirrors the server's code rule. */
export function slugify(value: string): string {
    return value
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/&/g, ' and ')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 50)
        .replace(/-+$/g, '');
}

/**
 * Yearly saving against 12 monthly payments, worked out in pence so the hint never shows float noise.
 * Returns null when either price is not a valid amount.
 */
export function yearlySaving(monthly: string, yearly: string): { saving: string; percent: number | null } | null {
    const toPence = (value: string): number | null => {
        const match = /^(\d{1,5})(?:\.(\d{1,2}))?$/.exec(value.replace(/[£,\s]/g, ''));

        return match ? Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0')) : null;
    };

    const monthlyPence = toPence(monthly);
    const yearlyPence = toPence(yearly);

    if (monthlyPence === null || yearlyPence === null) {
        return null;
    }

    const twelve = monthlyPence * 12;
    const savingPence = twelve - yearlyPence;

    return {
        saving: (savingPence / 100).toFixed(2),
        percent: twelve === 0 ? null : Math.round((savingPence / twelve) * 100),
    };
}

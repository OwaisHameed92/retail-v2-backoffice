import { type StatDelta } from '@/components/shared/stat-card';

const gbp = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', minimumFractionDigits: 2, maximumFractionDigits: 2 });
const gbpWhole = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', maximumFractionDigits: 0 });
const gbpCompact = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP', notation: 'compact', maximumFractionDigits: 1 });
const count = new Intl.NumberFormat('en-GB');
const dayFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });
const dayYearFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
const weekdayFormat = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });

/** "£1,234.56" from a decimal string; null → "—". Display only: the server does the sums. */
export function money(value: string | number | null | undefined): string {
    return value === null || value === undefined ? '—' : gbp.format(Number(value));
}

/** "£1,235" for big tiles; pence shown under £1,000. */
export function moneyShort(value: string | number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }
    const n = Number(value);

    return Math.abs(n) >= 1000 ? gbpWhole.format(n) : gbp.format(n);
}

/** Axis ticks: "£1.2K". */
export function moneyAxis(value: number): string {
    return gbpCompact.format(value);
}

export function number(value: string | number | null | undefined): string {
    return value === null || value === undefined ? '—' : count.format(Number(value));
}

/** "24 Sept" from "2026-09-24". */
export function shortDay(day: string): string {
    return dayFormat.format(new Date(`${day}T00:00:00Z`));
}

export function weekday(day: string): string {
    return weekdayFormat.format(new Date(`${day}T00:00:00Z`));
}

/** "1 – 30 Sept 2026", "24 Sept 2026". */
export function dayRange(from: string, to: string): string {
    if (from === to) {
        return dayYearFormat.format(new Date(`${from}T00:00:00Z`));
    }

    return `${shortDay(from)} – ${dayYearFormat.format(new Date(`${to}T00:00:00Z`))}`;
}

/** "09:00" for hour 9. */
export function hourLabel(hour: number): string {
    return `${String(hour).padStart(2, '0')}:00`;
}

/** A KpiCard delta from a change percentage ("12.5"); undefined when there is none. */
export function changeDelta(change: string | null | undefined, label: string, goodWhen: 'up' | 'down' = 'up'): StatDelta | undefined {
    if (change === null || change === undefined || label === '') {
        return undefined;
    }
    const n = Number(change);

    return {
        value: `${Math.abs(n).toLocaleString('en-GB', { maximumFractionDigits: 1 })}%`,
        direction: n > 0 ? 'up' : n < 0 ? 'down' : 'flat',
        goodWhen,
        label,
    };
}

/** Share of a total as a whole percentage, for bars. */
export function share(part: string | number, total: string | number): number {
    const t = Number(total);

    return t > 0 ? Math.max(0, Math.min(100, (Number(part) / t) * 100)) : 0;
}

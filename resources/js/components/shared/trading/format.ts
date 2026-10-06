import { type StatDelta } from '@/components/shared/stat-card';
import { dateFormat, formatMoney, formatMoneyCompact, formatMoneyWhole, formatNumber } from '@/lib/country';

const dayFormat = () => dateFormat({ day: 'numeric', month: 'short', timeZone: 'UTC' });
const dayYearFormat = () => dateFormat({ day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
const weekdayFormat = () => dateFormat({ weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });

/** "£1,234.56" (GB), "Rs 1,235" (PK) from a decimal string; null → "—". Display only: the server does the sums. */
export function money(value: string | number | null | undefined): string {
    return formatMoney(value);
}

/** "£1,235" for big tiles; pence shown under 1,000. */
export function moneyShort(value: string | number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }
    const n = Number(value);

    return Math.abs(n) >= 1000 ? formatMoneyWhole(n) : formatMoney(n);
}

/** Axis ticks: "£1.2K". */
export function moneyAxis(value: number): string {
    return formatMoneyCompact(value);
}

export function number(value: string | number | null | undefined): string {
    return value === null || value === undefined ? '—' : formatNumber(Number(value));
}

/** "24 Sept" from "2026-09-24". */
export function shortDay(day: string): string {
    return dayFormat().format(new Date(`${day}T00:00:00Z`));
}

export function weekday(day: string): string {
    return weekdayFormat().format(new Date(`${day}T00:00:00Z`));
}

/** "1 – 30 Sept 2026", "24 Sept 2026". */
export function dayRange(from: string, to: string): string {
    if (from === to) {
        return dayYearFormat().format(new Date(`${from}T00:00:00Z`));
    }

    return `${shortDay(from)} – ${dayYearFormat().format(new Date(`${to}T00:00:00Z`))}`;
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
        value: `${formatNumber(Math.abs(n), { maximumFractionDigits: 1 })}%`,
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

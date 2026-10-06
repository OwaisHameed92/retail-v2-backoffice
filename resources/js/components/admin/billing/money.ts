import { formatMinorUnits, stripMoney } from '@/lib/country';

/**
 * Money as exact integer minor units (pence, paisa) on the client (no floating point sums). Only for UI arithmetic in dialogs; the
 * server does the real maths with bcmath.
 */

/** "£1,234.5" / "1234.50" / "90" → 123450. Null when it is not a valid amount with up to 2 decimal places. */
export function toPence(value: string | null | undefined): number | null {
    const clean = stripMoney(value ?? '');
    const match = /^(\d{1,7})(?:\.(\d{1,2}))?$/.exec(clean);
    if (!match) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

/** 123450 → "1234.50" (for inputs and requests). */
export function fromPence(pence: number): string {
    const sign = pence < 0 ? '-' : '';
    const abs = Math.abs(pence);

    return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}
/** 123450 → "£1,234.50" (GB), "Rs 1,235" (PK). */
export function formatPence(pence: number): string {
    return formatMinorUnits(pence);
}

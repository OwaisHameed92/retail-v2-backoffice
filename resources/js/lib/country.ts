/**
 * Money, numbers and dates in this instance's country profile (Pakistan plan P0). The profile comes from the shared
 * Inertia prop `country` (config/country.php): app.tsx calls `setCountry()` once from the first page, so these plain
 * functions work outside components too; components can read the profile with `useCountry()`.
 *
 * GB output equals today's hard-coded 'en-GB' / 'GBP' / 'Europe/London' formatters (`shared/trading/format.ts`,
 * `admin/billing/money.ts`): "£1,234.50", "-£5.00", "24 Sept 2026". Callers move here in phase P2.
 */
import { type CountryProfile, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/** Used until `setCountry()` runs (and if a page ever lacks the prop): the UK, as before the profiles existed. */
export const GB_PROFILE: CountryProfile = {
    code: 'GB',
    name: 'United Kingdom',
    currency: 'GBP',
    currencySymbol: '£',
    currencySymbolSpace: false,
    displayDecimals: 2,
    grouping: 'thousands',
    numberLocale: 'en-GB',
    dateLocale: 'en-GB',
    timezone: 'Europe/London',
    taxName: 'VAT',
    taxIds: {
        vatNumber: { label: 'VAT number', example: 'GB123456789' },
        companyNumber: { label: 'Companies House number', example: '01234567' },
    },
    address: { postcodeLabel: 'Postcode', postcodeRequired: true, postcodeExample: 'LS1 6AB', cityRequired: false },
    phoneExample: '07700 900123',
    billingCollection: 'gocardless',
    features: { vatReturn: true, fbr: false },
};

let current: CountryProfile = GB_PROFILE;
const cache = new Map<string, Intl.NumberFormat | Intl.DateTimeFormat>();

/** Called once from app.tsx with the first page's `country` prop. */
export function setCountry(profile: CountryProfile | null | undefined): void {
    current = profile ?? GB_PROFILE;
    cache.clear();
}

export function country(): CountryProfile {
    return current;
}

/** The profile inside a component (the shared prop, else the one set at start-up). */
export function useCountry(): CountryProfile {
    return usePage<SharedData>().props.country ?? current;
}

/** "VAT" on GB, "GST" on PK. */
export function taxName(): string {
    return current.taxName;
}

type Amount = string | number | null | undefined;

const DASH = '—';

function numberFormat(options: Intl.NumberFormatOptions): Intl.NumberFormat {
    const key = `n|${current.numberLocale}|${JSON.stringify(options)}`;
    let format = cache.get(key) as Intl.NumberFormat | undefined;
    if (!format) {
        format = new Intl.NumberFormat(current.numberLocale, options);
        cache.set(key, format);
    }

    return format;
}

function dateFormat(options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
    const key = `d|${current.dateLocale}|${JSON.stringify(options)}`;
    let format = cache.get(key) as Intl.DateTimeFormat | undefined;
    if (!format) {
        format = new Intl.DateTimeFormat(current.dateLocale, options);
        cache.set(key, format);
    }

    return format;
}

/** "1234567" → "12,34,567": the last three digits, then pairs. */
function lakh(digits: string): string {
    if (digits.length <= 3) {
        return digits;
    }

    return `${digits.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',')},${digits.slice(-3)}`;
}

/** Lakh profiles format without Intl grouping (CLDR 'en-PK' groups in thousands) and regroup in `joinParts`. */
function grouping(options: Intl.NumberFormatOptions): Intl.NumberFormatOptions {
    return current.grouping === 'lakh' ? { ...options, useGrouping: false } : options;
}

/** Intl parts as text; thousands profiles (GB) keep Intl's own output untouched. */
function joinParts(parts: Intl.NumberFormatPart[]): string {
    return parts.map((part) => (part.type === 'integer' && current.grouping === 'lakh' ? lakh(part.value) : part.value)).join('');
}

/** Intl currency output with the profile's symbol ("£", "Rs") and spacing, whatever the browser's own symbol. */
function currency(value: number, decimals: number): string {
    const parts = numberFormat(
        grouping({
            style: 'currency',
            currency: current.currency,
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }),
    ).formatToParts(value);

    return joinParts(
        parts.map((part) => {
            if (part.type === 'currency') {
                return { ...part, value: current.currencySymbol + (current.currencySymbolSpace ? ' ' : '') };
            }

            // \s covers the no-break spaces Intl puts after "Rs".
            return part.type === 'literal' && /^\s+$/.test(part.value) ? { ...part, value: '' } : part;
        }),
    );
}

/** "£1,234.50" (GB), "Rs 1,250" (PK) from a decimal string; null → "—". Display only: the server does the sums. */
export function formatMoney(value: Amount): string {
    return value === null || value === undefined ? DASH : currency(Number(value), current.displayDecimals);
}

/** "£1,235" for big tiles: no decimals. */
export function formatMoneyWhole(value: Amount): string {
    return value === null || value === undefined ? DASH : currency(Number(value), 0);
}

/** "1,234" / "1,234.5" in the profile's locale and grouping (PK "1,25,000"); null → "—". */
export function formatNumber(value: Amount, options: Intl.NumberFormatOptions = {}): string {
    return value === null || value === undefined ? DASH : joinParts(numberFormat(grouping(options)).formatToParts(Number(value)));
}

type DateValue = string | number | Date | null | undefined;

const CALENDAR_DAY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * A moment shown in the profile's time zone. A calendar day ("2026-09-24", a trading day) is shown as that day,
 * whatever the zone.
 */
function formatIn(value: DateValue, options: Intl.DateTimeFormatOptions): string {
    if (value === null || value === undefined || value === '') {
        return DASH;
    }
    if (typeof value === 'string' && CALENDAR_DAY.test(value)) {
        return dateFormat({ ...options, timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`));
    }
    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? DASH : dateFormat({ timeZone: current.timezone, ...options }).format(date);
}

/** "24 Sept 2026". */
export function formatDate(value: DateValue, options: Intl.DateTimeFormatOptions = {}): string {
    return formatIn(value, { day: 'numeric', month: 'short', year: 'numeric', ...options });
}

/** "24 Sept 2026, 09:41". */
export function formatDateTime(value: DateValue, options: Intl.DateTimeFormatOptions = {}): string {
    return formatIn(value, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', ...options });
}

/** "09:41". */
export function formatTime(value: DateValue, options: Intl.DateTimeFormatOptions = {}): string {
    return formatIn(value, { hour: '2-digit', minute: '2-digit', ...options });
}

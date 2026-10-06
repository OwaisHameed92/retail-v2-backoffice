/**
 * Money, numbers and dates in this instance's country profile (Pakistan plan P0). The profile comes from the shared
 * Inertia prop `country` (config/country.php): app.tsx calls `setCountry()` once from the first page, so these plain
 * functions work outside components too; components can read the profile with `useCountry()`.
 *
 * GB output equals the hard-coded 'en-GB' / 'GBP' / 'Europe/London' formatters the pages used before the profiles:
 * "£1,234.50", "-£5.00", "24 Sept 2026". Since phase P2 every money, number and date formatter of the front end goes
 * through here (no `Intl.*('en-GB')` or `£` left in components).
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
const cache = new Map<string, Intl.NumberFormat | Intl.DateTimeFormat | Intl.RelativeTimeFormat>();

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

/**
 * The profile's IANA time zone: "Europe/London" (GB), "Asia/Karachi" (PK). Read it when formatting, never at module
 * load: the first page's modules are imported before `setCountry()` runs.
 */
export function timeZone(): string {
    return current.timezone;
}

/** The zone's city for labels: "London" (GB, so "London time" reads as before), "Karachi" (PK). */
export function timeZoneCity(): string {
    return (current.timezone.split('/').pop() ?? current.timezone).replace(/_/g, ' ');
}

/** Who the shop time belongs to, for "… time" labels: "UK" (GB, so "UK time" reads as before), "Pakistan" (PK). */
export function timeZoneLabel(): string {
    return current.code === 'GB' ? 'UK' : current.name;
}

/**
 * An `Intl.DateTimeFormat` for `locale` in the profile's time zone, built on first use and cached until
 * `setCountry()`. For formatters that keep their own locale and options (the locale moves to the profile in P2).
 *
 *     zonedDateFormat('en-GB', { day: 'numeric', month: 'short' }).format(date); // "24 Sept" in shop time
 *     zonedDateFormat('en-CA').format(new Date());                              // today as "2026-09-24"
 */
export function zonedDateFormat(locale: string, options: Intl.DateTimeFormatOptions = {}): Intl.DateTimeFormat {
    const key = `z|${locale}|${current.timezone}|${JSON.stringify(options)}`;
    let format = cache.get(key) as Intl.DateTimeFormat | undefined;
    if (!format) {
        format = new Intl.DateTimeFormat(locale, { ...options, timeZone: current.timezone });
        cache.set(key, format);
    }

    return format;
}

/** "VAT" on GB, "GST" on PK. */
export function taxName(): string {
    return current.taxName;
}

/**
 * Display text in the profile's tax name (phase P3): GB returns it unchanged, PK "Sales (inc VAT)" → "Sales (inc GST)".
 * For words shown to people only (never keys, columns or contract fields). Call it when rendering, never at module
 * load: the first page's modules are imported before `setCountry()` runs.
 */
export function taxText(text: string): string {
    return current.taxName === 'VAT' ? text : text.replace(/\bVAT\b/g, current.taxName);
}

/** True where the HMRC VAT return (boxes 1–9) is offered: GB. Its pages answer 404 elsewhere. */
export function hasVatReturn(): boolean {
    return current.features.vatReturn === true;
}

type TaxId = { label: string; example: string };

/** Business columns → the taxIds keys they may hold, first found wins (as `Country::taxIdFor`). */
const TAX_ID_COLUMNS: Record<'vat_number' | 'strn' | 'company_number', string[]> = {
    vat_number: ['vatNumber', 'ntn'],
    strn: ['strn'],
    company_number: ['companyNumber'],
};

/**
 * The tax id a business column holds, null when the profile has none: `vat_number` is the VAT number (GB) or the NTN
 * (PK), `strn` the STRN (PK only), `company_number` the Companies House (GB) or SECP (PK) number.
 */
export function taxIdFor(column: keyof typeof TAX_ID_COLUMNS): TaxId | null {
    const key = TAX_ID_COLUMNS[column].find((k) => current.taxIds[k] !== undefined);

    return key ? current.taxIds[key] : null;
}

/** The `vat_number` field's label: "VAT number" (GB), "NTN" (PK). */
export function vatNumberLabel(): string {
    return taxIdFor('vat_number')?.label ?? `${current.taxName} number`;
}

/** The `company_number` field's label: "Company number" (GB, as the forms always read), "SECP registration number" (PK). */
export function companyNumberLabel(): string {
    return keepsUkStyles() ? 'Company number' : (taxIdFor('company_number')?.label ?? 'Company number');
}

/** What documents print before a stored VAT number: "VAT no." (GB, as before), "NTN" (PK). */
export function vatNumberPrefix(): string {
    return keepsUkStyles() ? 'VAT no.' : vatNumberLabel();
}

/** "£" (GB), "Rs" (PK): for input prefixes and labels such as `Price (${currencySymbol()})`. */
export function currencySymbol(): string {
    return current.currencySymbol;
}

/** What goes before an amount: "£" (GB), "Rs " (PK). */
export function moneyPrefix(): string {
    return current.currencySymbol + (current.currencySymbolSpace ? ' ' : '');
}

/** True when the symbol is wider than one character ("Rs"): money inputs then need more room for the prefix. */
export function wideCurrencySymbol(): boolean {
    return current.currencySymbol.length > 1;
}

/**
 * The browser check on typed money (an `<input pattern>`). GB keeps the exact rule it always had (up to £99,999.99,
 * digits and a point only). Other currencies (PKR) allow larger amounts typed with grouping commas, "1,00,000" or
 * "100,000.00": the server strips the symbol and commas and checks up to 9 digits (BillingRequest::LARGE_MONEY_PATTERN).
 */
export function moneyInputPattern(): string {
    return keepsUkStyles() ? '^\\d{1,5}(\\.\\d{1,2})?$' : '^\\d[\\d,]{0,12}(\\.\\d{1,2})?$';
}

/** The currency in words for text: "pounds" (GB), "rupees" (PK). */
export function currencyName(): string {
    return ({ GBP: 'pounds', PKR: 'rupees' } as Record<string, string>)[current.currency] ?? current.currency;
}

/** True on the default profile (GB), whose screens keep the money styles they always had (see `formatMoneyAsGiven`). */
export function keepsUkStyles(): boolean {
    return current.code === GB_PROFILE.code;
}

/** The profile's locale for numbers: "en-GB", "en-PK". */
export function numberLocale(): string {
    return current.numberLocale;
}

/** The profile's locale for dates and relative times: "en-GB", "en-PK". */
export function dateLocale(): string {
    return current.dateLocale;
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

/**
 * An `Intl.DateTimeFormat` in the profile's date locale with the given options (no time zone added: pass
 * `timeZone: 'UTC'` for calendar days), cached until `setCountry()`. Build it when formatting, never at module load.
 */
export function dateFormat(options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
    const key = `d|${current.dateLocale}|${JSON.stringify(options)}`;
    let format = cache.get(key) as Intl.DateTimeFormat | undefined;
    if (!format) {
        format = new Intl.DateTimeFormat(current.dateLocale, options);
        cache.set(key, format);
    }

    return format;
}

/** An `Intl.RelativeTimeFormat` in the profile's date locale ("yesterday", "in 3 days"), cached until `setCountry()`. */
export function relativeTimeFormat(options: Intl.RelativeTimeFormatOptions = {}): Intl.RelativeTimeFormat {
    const key = `r|${current.dateLocale}|${JSON.stringify(options)}`;
    let format = cache.get(key) as Intl.RelativeTimeFormat | undefined;
    if (!format) {
        format = new Intl.RelativeTimeFormat(current.dateLocale, options);
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
function currency(value: number, decimals: number, options: Intl.NumberFormatOptions = {}): string {
    const parts = numberFormat(
        grouping({
            style: 'currency',
            currency: current.currency,
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
            ...options,
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

/** "£60", "£60.50" (GB): decimals only when the amount has them; PK "Rs 60" (whole rupees). */
export function formatMoneyTrim(value: Amount): string {
    return value === null || value === undefined ? DASH : currency(Number(value), current.displayDecimals, { minimumFractionDigits: 0 });
}

/** Chart axes: "£1.2K", "Rs 1.3M". */
export function formatMoneyCompact(value: Amount): string {
    return value === null || value === undefined
        ? DASH
        : currency(Number(value), 0, { notation: 'compact', minimumFractionDigits: undefined, maximumFractionDigits: 1 });
}

/**
 * A cost or unit price with up to 4 decimal places, at least the profile's display decimals: GB "£0.4575", "£1.50"
 * (the symbol and the number, as the cost columns always read), PK "Rs 12.5", "Rs 1,250".
 */
export function formatCost(value: Amount): string {
    return value === null || value === undefined
        ? DASH
        : moneyPrefix() + formatNumber(value, { minimumFractionDigits: current.displayDecimals, maximumFractionDigits: 4 });
}

/**
 * A raw decimal as GB screens have always shown it, the symbol and the text as given ("£1250.5", "£2.50"); any other
 * profile formats it properly (`formatMoney`: "Rs 1,251"). For places that printed `£${value}`.
 */
export function formatMoneyAsGiven(value: string | number | null | undefined): string {
    if (keepsUkStyles()) {
        return `${current.currencySymbol}${value ?? ''}`;
    }

    return value === null || value === undefined || value === '' ? DASH : formatMoney(value);
}

/**
 * Exact integer minor units (pence, paisa) as money without floating point sums: GB 123450 → "£1,234.50";
 * PK → "Rs 1,235" (whole rupees, half away from zero).
 */
export function formatMinorUnits(minor: number): string {
    const abs = Math.abs(minor);
    if (current.displayDecimals === 2) {
        return `${minor < 0 ? '-' : ''}${moneyPrefix()}${formatNumber(Math.floor(abs / 100))}.${String(abs % 100).padStart(2, '0')}`;
    }
    const whole = Math.round(abs / 100);

    return `${minor < 0 && whole !== 0 ? '-' : ''}${moneyPrefix()}${formatNumber(whole)}`;
}

/** Text typed as money ("£1,234.5", "Rs 1,250") without the symbol, separators and spaces: "1234.5". */
export function stripMoney(value: string): string {
    return value.split(current.currencySymbol).join('').replace(/[,\s]/g, '');
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

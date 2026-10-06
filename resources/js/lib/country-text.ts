/**
 * UK-only words in page text (Pakistan plan P6): legal wording, example emails and web addresses, UK marketing lines.
 * GB returns exactly what the pages showed before; other profiles get neutral or local wording. Call these when
 * rendering, never at module load (the profile is set after the first page's modules are imported).
 */
import { country } from '@/lib/country';

function isUk(): boolean {
    return country().code === 'GB';
}

/** The UK text on GB, the neutral or local text elsewhere: `ukOnly('Your rights under UK GDPR', 'Your rights')`. */
export function ukOnly(gb: string, other: string): string {
    return isUk() ? gb : other;
}

/**
 * UK sample places in examples ("e.g. Leeds", "LDS-01-000482"): unchanged on GB; the profile's own elsewhere
 * ("e.g. Lahore", "LHR-01-000482"; Pakistan plan P9). Longest names are replaced first.
 */
export function localPlaces(gb: string): string {
    const places = country().samplePlaces;
    if (isUk() || !places) {
        return gb;
    }
    const names = Object.keys(places).sort((a, b) => b.length - a.length);
    if (names.length === 0) {
        return gb;
    }
    const escaped = names.map((name) => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    return gb.replace(new RegExp(`\\b(${escaped.join('|')})\\b`, 'g'), (name) => places[name] ?? name);
}

/** "Bank holiday" is UK wording: unchanged on GB, "public holiday" elsewhere, keeping the first letter's case (P9). */
export function publicHolidays(gb: string): string {
    return isUk() ? gb : gb.replace(/\b([Bb])ank holiday/g, (_, b: string) => (b === 'B' ? 'Public holiday' : 'public holiday'));
}

/** Example emails and web addresses: unchanged on GB; ".co.uk" becomes the country's domain elsewhere ("you@yourshop.pk"). */
export function localDomains(gb: string): string {
    return isUk() ? gb : gb.replace(/\.co\.uk\b/g, `.${country().code.toLowerCase()}`);
}

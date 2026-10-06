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

/** Example emails and web addresses: unchanged on GB; ".co.uk" becomes the country's domain elsewhere ("you@yourshop.pk"). */
export function localDomains(gb: string): string {
    return isUk() ? gb : gb.replace(/\.co\.uk\b/g, `.${country().code.toLowerCase()}`);
}

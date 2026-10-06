/**
 * How monthly and yearly fees are collected on this instance (Pakistan plan P5): by GoCardless Direct Debit (GB) or
 * as invoices paid by hand (PK: bank transfer, JazzCash, Easypaisa, cash). GB pages keep their text exactly:
 * `byHand(gb, manual)` returns the GB text unless fees are collected by hand. Call these when rendering, never at
 * module load (the profile is set after the first page's modules are imported).
 */
import { country } from '@/lib/country';

const METHOD_LABELS: Record<string, string> = {
    cash: 'Cash',
    card: 'Card',
    bankTransfer: 'Bank transfer',
    other: 'Other',
    jazzCash: 'JazzCash',
    easypaisa: 'Easypaisa',
};

/** Brand names keep their capitals inside a sentence. */
const BRANDS = ['jazzCash', 'easypaisa'];

/** True where every invoice is paid by hand and there is no Direct Debit (PK). */
export function manualCollection(): boolean {
    return country().billingCollection === 'manual';
}

/** The GB text where fees are collected by Direct Debit, the manual-collection text elsewhere. */
export function byHand<T>(gb: T, manual: T): T {
    return manualCollection() ? manual : gb;
}

/** The methods staff record by hand on a manual-collection instance, as select options. */
export function manualMethodOptions<T extends string = string>(): { value: T; label: string }[] {
    return (country().manualMethods ?? []).map((value) => ({ value: value as T, label: METHOD_LABELS[value] ?? value }));
}

/** "bank transfer, JazzCash, Easypaisa or cash". */
export function manualMethodsText(): string {
    const words = (country().manualMethods ?? []).map((value) =>
        BRANDS.includes(value) ? (METHOD_LABELS[value] ?? value) : (METHOD_LABELS[value] ?? value).toLowerCase(),
    );

    if (words.length <= 1) {
        return words[0] ?? '';
    }

    return `${words.slice(0, -1).join(', ')} or ${words[words.length - 1]}`;
}

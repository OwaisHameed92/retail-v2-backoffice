import { formatMoney } from '@/lib/country';
import { TINTS } from './theme';
import { type StoreProduct, type StoreTemplate } from './types';

/** "£1.10" (the instance's currency). Display only: the server does the sums. */
export const money = (value: string | number): string => formatMoney(value);

/** "£9.80/kg" for weighed products, else "£1.10". */
export function priceLabel(product: Pick<StoreProduct, 'price' | 'isWeighed'>): string {
    return product.isWeighed ? `${money(product.price)}/kg` : money(product.price);
}

/** "3" or "1.25 kg". */
export function quantityLabel(quantity: string, weighed: boolean): string {
    return weighed ? `${trimNumber(quantity)} kg` : trimNumber(quantity);
}

export function trimNumber(value: string | number): string {
    const n = Number(value);

    return Number.isFinite(n) ? String(Math.round(n * 100) / 100) : String(value);
}

/** Quantities in hundredths, so 0.25 kg steps never pick up float noise. */
export function stepQuantity(value: string, step: string, direction: 1 | -1, min: string, max: string): string {
    const cents = (v: string) => Math.round(Number(v) * 100);
    const next = Math.min(cents(max), Math.max(cents(min), cents(value) + direction * cents(step)));

    return trimNumber(next / 100);
}

/** The first add for a product: 1, or 0.5 kg for weighed products (within its limit). */
export function defaultQuantity(product: Pick<StoreProduct, 'isWeighed' | 'max'>): string {
    return product.isWeighed ? trimNumber(Math.min(0.5, Number(product.max))) : '1';
}

/** Estimated total for a quantity: price × quantity, rounded to the penny. */
export function lineEstimate(price: string, quantity: string): string {
    return (Math.round(Number(price) * Number(quantity) * 100) / 100).toFixed(2);
}

/** Text colour that reads on the brand colour: ink on light colours, white on dark ones (WCAG luminance). */
export function onColour(hex: string): string {
    const match = /^#?([0-9a-f]{6})$/i.exec(hex);

    if (!match) {
        return '#FFFFFF';
    }

    const channel = (i: number) => {
        const c = parseInt(match[1].slice(i, i + 2), 16) / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };
    const luminance = 0.2126 * channel(0) + 0.7152 * channel(2) + 0.0722 * channel(4);

    // Contrast with white vs with ink (#0E1712, luminance ~0.0075): pick the higher.
    return 1.05 / (luminance + 0.05) >= (luminance + 0.05) / 0.0575 ? '#FFFFFF' : '#0E1712';
}

/** A soft tint for a placeholder tile, stable per name, from the template's palette. */
export function tintFor(name: string, template: StoreTemplate = 'freshMarket'): string {
    const tints = TINTS[template] ?? TINTS.freshMarket;
    let hash = 0;

    for (let i = 0; i < name.length; i++) {
        hash = (hash * 31 + name.charCodeAt(i)) >>> 0;
    }

    return tints[hash % tints.length];
}

/** "500 g" or "1.5 kg". */
export function weightLabel(kg: string | number): string {
    const n = Number(kg);

    return n > 0 && n < 1 ? `${Math.round(n * 1000)} g` : `${trimNumber(n)} kg`;
}

/** Quick weights for a weighed product (500 g, 1 kg, 2 kg) within its step and limit; at least its first add. */
export function weightOptions(product: Pick<StoreProduct, 'isWeighed' | 'step' | 'max'>): string[] {
    const options = ['0.5', '1', '2'].filter((w) => Number(w) >= Number(product.step) && Number(w) <= Number(product.max));

    return options.length > 0 ? options : [defaultQuantity(product)];
}

export function initialOf(name: string): string {
    const letter = name.trim().match(/[\p{L}\p{N}]/u);

    return letter ? letter[0].toUpperCase() : '?';
}

/**
 * Address and phone form text from the country profile (Pakistan plan P4). The server validates (PHP
 * `ContactRules`); these helpers keep labels, placeholders, hints and the browser's `required` in step with it.
 * GB returns exactly what the forms showed before: "Postcode", the UK example numbers, its own required-ness.
 */
import { country } from '@/lib/country';

/** UK example numbers in today's placeholders, swapped for the profile's example elsewhere. */
const UK_PHONE_EXAMPLES = /0113 496 0000|07700 900123/g;

function isUk(): boolean {
    return country().code === 'GB';
}

/** The postcode field's label: "Postcode" (GB), "Postal code" (PK). */
export function postcodeLabel(): string {
    return country().address.postcodeLabel;
}

/** Whether a postcode the form requires on GB is required here: a profile only ever makes it optional (PK). */
export function postcodeRequired(requiredOnGb: boolean): boolean {
    return requiredOnGb && (isUk() || country().address.postcodeRequired);
}

/** Extra input props for a postcode: a number keypad where postcodes are digits only (PK "54000"); none on GB. */
export function postcodeInputProps(): { inputMode?: 'numeric'; placeholder?: string } {
    if (isUk()) return {};
    const example = country().address.postcodeExample;

    return /^\d+$/.test(example) ? { inputMode: 'numeric', placeholder: example } : { placeholder: example };
}

/** True where the town (city) must be filled in with an address (PK); never on GB. */
export function townNeededWithAddress(): boolean {
    return !isUk() && country().address.cityRequired;
}

/** The town field's hint where it is needed with an address; undefined on GB (no hint, as before). */
export function townHint(): string | undefined {
    return townNeededWithAddress() ? 'Needed when an address is entered.' : undefined;
}

/** Phone text or placeholder: unchanged on GB; elsewhere the UK example numbers become the profile's example. */
export function phoneText(gb: string): string {
    return isUk() ? gb : gb.replace(UK_PHONE_EXAMPLES, country().phoneExample);
}

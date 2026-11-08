/**
 * The public salon pages' JSON calls (W3a). No session or CSRF cookie is needed (the pages may sit inside another
 * site's frame); every write carries the form's own random key, so a double tap or a resend is one request.
 */

export interface SalonResult<T> {
    ok: boolean;
    status: number;
    data: T | null;
    /** First message per field (422), e.g. { time: 'That time has just gone.' }. */
    errors: Record<string, string>;
    message: string | null;
}

function failure(status: number, body: unknown): string {
    const message = typeof body === 'object' && body !== null && 'message' in body ? String((body as { message: unknown }).message) : '';

    switch (status) {
        case 404:
            return 'This page is no longer available. Ring the shop to book.';
        case 422:
            return message || 'Check the form and try again.';
        case 429:
            return 'Too many tries in a short time. Wait a minute and try again.';
        default:
            return 'Something went wrong. Try again, or ring the shop.';
    }
}

export async function salonSend<T>(method: 'GET' | 'POST', url: string, body?: unknown, signal?: AbortSignal): Promise<SalonResult<T>> {
    let response: Response;

    try {
        response = await fetch(url, {
            method,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
            },
            credentials: 'same-origin',
            cache: 'no-store',
            body: body === undefined ? undefined : JSON.stringify(body),
            signal,
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }

        return { ok: false, status: 0, data: null, errors: {}, message: 'We could not reach the server. Check your connection and try again.' };
    }

    const parsed: unknown = await response.json().catch(() => null);

    if (response.ok) {
        return { ok: true, status: response.status, data: parsed as T, errors: {}, message: null };
    }

    const raw = typeof parsed === 'object' && parsed !== null && 'errors' in parsed ? (parsed as { errors: Record<string, string[]> }).errors : {};
    const errors: Record<string, string> = {};

    for (const [field, messages] of Object.entries(raw ?? {})) {
        errors[field.split('.')[0]] = Array.isArray(messages) ? String(messages[0] ?? '') : String(messages);
    }

    return { ok: false, status: response.status, data: null, errors, message: failure(response.status, parsed) };
}

/** A random key for one form: the same key sent twice is one request. */
export function formKey(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}

const pounds = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' });

export function money(value: string | number): string {
    return pounds.format(Number(value));
}

export function minutes(total: number): string {
    if (total < 60) {
        return `${total} min`;
    }

    const hours = Math.floor(total / 60);
    const rest = total % 60;

    return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
}

/** A `tel:` address for a shop phone. */
export function telHref(phone: string): string {
    return `tel:${phone.replace(/[^0-9+]/g, '')}`;
}

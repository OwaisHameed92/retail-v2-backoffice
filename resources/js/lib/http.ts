/**
 * JSON requests outside Inertia, for the few admin replies that must not become an Inertia page or a session
 * flash: new licence keys (shown once) and the top-bar search. Sends Laravel's CSRF cookie as X-XSRF-TOKEN.
 */

export interface JsonResult<T> {
    ok: boolean;
    status: number;
    data: T | null;
    /** First validation message per field (422), e.g. { name: 'Enter a name.' }. */
    errors: Record<string, string>;
    /** A message to show the user when the request failed. */
    message: string | null;
}

type Method = 'GET' | 'POST' | 'PUT' | 'DELETE';

export function xsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }
    const match = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : null;
}

function failureMessage(status: number, body: unknown): string {
    const message = typeof body === 'object' && body !== null && 'message' in body ? String((body as { message: unknown }).message) : '';

    switch (status) {
        case 401:
        case 419:
            return 'Your session has expired. Refresh the page and sign in again.';
        case 403:
            return 'You do not have permission to do that.';
        case 404:
            return 'This record no longer exists. Refresh the page.';
        case 422:
            return message || 'Check the form and try again.';
        case 429:
            return 'Too many requests. Wait a moment and try again.';
        default:
            return 'Something went wrong. Try again, or contact support if it keeps happening.';
    }
}

export async function sendJson<T>(method: Method, url: string, body?: unknown, signal?: AbortSignal): Promise<JsonResult<T>> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const token = xsrfToken();
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    let response: Response;
    try {
        response = await fetch(url, {
            method,
            headers,
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

    const errors: Record<string, string> = {};
    if (response.status === 422 && typeof parsed === 'object' && parsed !== null && 'errors' in parsed) {
        for (const [field, messages] of Object.entries((parsed as { errors: Record<string, string[] | string> }).errors)) {
            errors[field] = Array.isArray(messages) ? (messages[0] ?? '') : String(messages);
        }
    }

    const firstError = Object.values(errors)[0];

    return { ok: false, status: response.status, data: null, errors, message: firstError ?? failureMessage(response.status, parsed) };
}

/**
 * POSTs JSON and saves the file the server replies with (e.g. a PDF), as `fallbackName` unless the reply names it.
 * Returns null when saved, else the message to show.
 */
export async function postAndDownload(url: string, body: unknown, fallbackName: string): Promise<string | null> {
    const headers: Record<string, string> = {
        Accept: 'application/json, application/pdf',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = xsrfToken();
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    let response: Response;
    try {
        response = await fetch(url, { method: 'POST', headers, credentials: 'same-origin', cache: 'no-store', body: JSON.stringify(body) });
    } catch {
        return 'We could not reach the server. Check your connection and try again.';
    }

    if (!response.ok) {
        const parsed: unknown = await response.json().catch(() => null);
        const errors = typeof parsed === 'object' && parsed !== null && 'errors' in parsed ? (parsed as { errors: Record<string, string[]> }).errors : {};
        const first = Object.values(errors)[0]?.[0];

        return first ?? failureMessage(response.status, parsed);
    }

    const name = /filename="([^"]+)"/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? fallbackName;
    const href = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = href;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(href), 10_000);

    return null;
}

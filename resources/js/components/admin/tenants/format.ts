import { dateLocale, formatNumber, zonedDateFormat } from '@/lib/country';

const dateFormat = () => zonedDateFormat(dateLocale(), { day: 'numeric', month: 'short', year: 'numeric' });

const timeFormat = () => zonedDateFormat(dateLocale(), { hour: '2-digit', minute: '2-digit' });

/** "24 Sept 2026" in the profile's time zone. */
export function formatDate(iso: string | null | undefined, fallback = '—'): string {
    return iso ? dateFormat().format(new Date(iso)) : fallback;
}

/** "24 Sept 2026, 09:41" in the profile's time zone. */
export function formatDateTimeShort(iso: string | null | undefined, fallback = '—'): string {
    if (!iso) {
        return fallback;
    }
    const date = new Date(iso);

    return `${dateFormat().format(date)}, ${timeFormat().format(date)}`;
}

/** "2026-09-24" in the profile's time zone, for <input type="date">. */
export function toDateInput(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }
    const parts = zonedDateFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(iso));

    return parts;
}

export function plural(count: number, one: string, many = `${one}s`): string {
    return `${formatNumber(count)} ${count === 1 ? one : many}`;
}

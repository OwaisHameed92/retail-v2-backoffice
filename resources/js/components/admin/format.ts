import { zonedDateFormat } from '@/lib/country';

const dateTimeFormat = () =>
    zonedDateFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

/** Formats a UTC ISO timestamp for display in the profile's time zone. */
export function formatDateTime(iso: string | null, fallback = 'Never'): string {
    if (!iso) {
        return fallback;
    }

    return dateTimeFormat().format(new Date(iso));
}

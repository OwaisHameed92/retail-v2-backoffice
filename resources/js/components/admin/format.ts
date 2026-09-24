const dateTimeFormat = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Europe/London',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

/** Formats a UTC ISO timestamp for display in Europe/London. */
export function formatDateTime(iso: string | null, fallback = 'Never'): string {
    if (!iso) {
        return fallback;
    }

    return dateTimeFormat.format(new Date(iso));
}

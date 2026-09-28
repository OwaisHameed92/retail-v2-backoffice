const rtf = new Intl.RelativeTimeFormat('en-GB', { numeric: 'auto', style: 'narrow' });

/** Compact relative time for lists: "just now", "4m ago", "2h ago", "1d ago", "3w ago". Takes a UTC ISO string or Date. */
export function relativeTime(value: string | Date, now: Date = new Date()): string {
    const date = typeof value === 'string' ? new Date(value) : value;
    const seconds = Math.round((now.getTime() - date.getTime()) / 1000);

    if (Number.isNaN(seconds)) {
        return '';
    }
    if (Math.abs(seconds) < 60) {
        return 'just now';
    }
    const steps: [number, string][] = [
        [60, 'm'],
        [3600, 'h'],
        [86400, 'd'],
        [604800, 'w'],
    ];
    const abs = Math.abs(seconds);
    let [size, unit] = steps[0];
    for (const step of steps) {
        if (abs >= step[0]) {
            [size, unit] = step;
        }
    }
    const amount = Math.floor(abs / size);

    if (unit === 'w' && amount > 8) {
        return rtf.format(-Math.floor(abs / 2592000), 'month');
    }

    return seconds >= 0 ? `${amount}${unit} ago` : `in ${amount}${unit}`;
}

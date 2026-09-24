import { type StatusToneMap } from '@/components/shared/status-badge';
import { type BusinessType, type LeadSource, type LeadStatus } from './types';

export { formatDate, formatDateTimeShort, plural, toDateInput } from '@/components/admin/tenants/format';

/** Calm tones: new asks for a first call, contacted is in progress, converted is won, rejected is closed. */
export const leadStatusTones: StatusToneMap = {
    new: 'info',
    contacted: 'warning',
    approved: 'success',
    converted: 'success',
    rejected: 'neutral',
};

export const leadStatusLabels: Record<LeadStatus, string> = {
    new: 'New',
    contacted: 'Contacted',
    approved: 'Approved',
    rejected: 'Rejected',
    converted: 'Converted',
};

export const leadStatusHelp: Record<LeadStatus, string> = {
    new: 'Nobody has spoken to them yet.',
    contacted: 'We have spoken to them. Approve the trial or reject it.',
    approved: 'Trial approved.',
    rejected: 'We turned the request down. Reopen it if they get back in touch.',
    converted: 'The trial is approved and the customer is set up as a tenant.',
};

export const leadSourceLabels: Record<LeadSource, string> = {
    website: 'Website',
    phone: 'Phone call',
    referral: 'Referral',
    walkIn: 'Walk-in',
    other: 'Other',
};

export const businessTypeLabels: Record<BusinessType, string> = {
    convenience: 'Convenience store',
    offLicence: 'Off-licence',
    newsagent: 'Newsagent',
    grocery: 'Grocery',
    forecourt: 'Forecourt',
    other: 'Other',
};

const LONDON = 'Europe/London';

const dayKey = (date: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: LONDON, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);

const timeFormat = new Intl.DateTimeFormat('en-GB', { timeZone: LONDON, hour: '2-digit', minute: '2-digit' });

const shortDate = new Intl.DateTimeFormat('en-GB', { timeZone: LONDON, day: 'numeric', month: 'short' });

const weekday = new Intl.DateTimeFormat('en-GB', { timeZone: LONDON, weekday: 'short' });

export type FollowUpState = 'overdue' | 'today' | 'upcoming';

/** Where a follow-up stands right now (Europe/London days). */
export function followUpState(iso: string, now: Date = new Date()): FollowUpState {
    const at = new Date(iso);
    if (at.getTime() < now.getTime()) {
        return 'overdue';
    }

    return dayKey(at) === dayKey(now) ? 'today' : 'upcoming';
}

/** "Today 14:30", "Tomorrow 09:00", "Fri 09:00", "3 Oct 09:00" (Europe/London). */
export function formatFollowUp(iso: string, now: Date = new Date()): string {
    const at = new Date(iso);
    const time = timeFormat.format(at);
    const days = Math.round((Date.parse(dayKey(at)) - Date.parse(dayKey(now))) / 86_400_000);

    if (days === 0) return `Today ${time}`;
    if (days === 1) return `Tomorrow ${time}`;
    if (days === -1) return `Yesterday ${time}`;
    if (days > 1 && days < 7) return `${weekday.format(at)} ${time}`;

    return `${shortDate.format(at)} ${time}`;
}

/** "07700 900123" → "tel:07700900123". */
export function telHref(phone: string): string {
    return `tel:${phone.replace(/[^\d+]/g, '')}`;
}

const VOWELS = new Set(['A', 'E', 'I', 'O', 'U']);

function baseCode(name: string): string {
    const words = name
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toUpperCase()
        .split(/[^A-Z]+/)
        .filter(Boolean);

    if (words.length >= 2) {
        const initials = words
            .slice(0, 3)
            .map((word) => word[0])
            .join('');

        return initials.length >= 2 ? initials : 'SHP';
    }

    const word = words[0] ?? '';
    if (word.length < 2) {
        return word === '' ? 'SHP' : `${word}X`;
    }

    let code = word[0];
    const rest = word.slice(1).split('');
    for (const char of rest) {
        if (code.length === 3) break;
        if (!VOWELS.has(char)) code += char;
    }
    for (const char of rest) {
        if (code.length === 3) break;
        if (VOWELS.has(char)) code += char;
    }

    return code;
}

/**
 * Branch code from a shop name, same rules as App\Domain\Leads\Support\BranchCodeSuggester:
 * "Leeds" → LDS, "Khan Mini Mart" → KMM; a taken code gets another last letter, then a fourth letter.
 */
export function suggestBranchCode(name: string, taken: string[] = []): string {
    const base = baseCode(name);
    if (!taken.includes(base)) {
        return base;
    }
    const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
    for (const letter of letters) {
        const candidate = base.slice(0, 2) + letter;
        if (!taken.includes(candidate)) return candidate;
    }
    for (const letter of letters) {
        const candidate = base.slice(0, 3) + letter;
        if (!taken.includes(candidate)) return candidate;
    }

    return `${base.slice(0, 2)}XYZ`;
}

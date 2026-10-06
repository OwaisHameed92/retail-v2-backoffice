import { StatusBadge, StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { dateLocale, relativeTimeFormat, zonedDateFormat } from '@/lib/country';
import { type HealthProblem, type SyncState, type TillState } from './types';

export const tillStateTones: Record<TillState, StatusTone> = {
    online: 'success',
    stale: 'warning',
    offline: 'danger',
    notActivated: 'neutral',
};

export const syncStateTones: Record<SyncState, StatusTone> = {
    notLinked: 'neutral',
    healthy: 'success',
    failing: 'danger',
    stalled: 'warning',
};

export function TillStateBadge({ state, label }: { state: TillState; label: string }) {
    return <StatusBadge status={state} label={label} tone={tillStateTones[state]} />;
}

export function SyncStateBadge({ state, label }: { state: SyncState; label: string }) {
    return <StatusBadge status={state} label={label} tone={syncStateTones[state]} />;
}

const problemTones: Record<HealthProblem['value'], StatusTone> = {
    offline: 'danger',
    oldVersion: 'warning',
    syncFailing: 'danger',
    syncStalled: 'violet',
    clockSkew: 'warning',
};

/** Toned pills, one per problem; "None" in muted text when all is well. */
export function ProblemPills({ problems, empty = 'None' }: { problems: HealthProblem[]; empty?: string }) {
    if (problems.length === 0) {
        return <span className="text-muted-foreground text-sm">{empty}</span>;
    }

    return (
        <span className="flex flex-wrap gap-1">
            {problems.map((problem) => (
                <StatusPill key={problem.value} tone={problemTones[problem.value]}>
                    {problem.label}
                </StatusPill>
            ))}
        </span>
    );
}

const relative = () => relativeTimeFormat({ numeric: 'auto' });
const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
];

/** "3 hours ago", "just now", or the fallback when there is no time. */
export function ago(iso: string | null | undefined, fallback = 'Never'): string {
    if (!iso) {
        return fallback;
    }
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    if (Math.abs(seconds) < 60) {
        return 'just now';
    }
    for (const [unit, size] of UNITS) {
        if (Math.abs(seconds) >= size) {
            return relative().format(Math.round(seconds / size), unit);
        }
    }

    return relative().format(Math.round(seconds / 60), 'minute');
}

const dateTime = () => zonedDateFormat(dateLocale(), { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

/** "7 Oct 2026, 12:04" in shop time. */
export function shopDateTime(iso: string | null | undefined): string {
    return iso ? dateTime().format(new Date(iso)) : '';
}

/** "2 min fast", "40 s slow", "In step" (till clock minus portal time). */
export function clockSkewText(seconds: number | null): string {
    if (seconds === null) {
        return 'Not reported';
    }
    const size = Math.abs(seconds);
    if (size < 5) {
        return 'In step';
    }
    const amount = size < 120 ? `${size} s` : size < 7200 ? `${Math.round(size / 60)} min` : `${Math.round(size / 3600)} h`;

    return `${amount} ${seconds > 0 ? 'fast' : 'slow'}`;
}

/** How the states are decided, for help text. */
export function thresholdsText(t: {
    syncOnlineMinutes: number;
    validateOnlineHours: number;
    validateOfflineHours: number;
    syncOfflineHours: number;
}): string {
    return `Online: the main till synced within ${t.syncOnlineMinutes} minutes, or any other till checked in within ${t.validateOnlineHours} hours. Offline: no sync for ${t.syncOfflineHours} hours and no check-in for ${t.validateOnlineHours} hours (main till), or no check-in for ${t.validateOfflineHours} hours (other tills).`;
}

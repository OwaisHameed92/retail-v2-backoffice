import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { ago } from '@/components/till-health/format';
import { formatDateTime, formatNumber } from '@/lib/country';
import { type HealthState, type ProblemKind, type RunState } from './types';

export const healthTones: Record<HealthState, StatusTone> = { ok: 'success', warning: 'warning', problem: 'danger', unknown: 'neutral' };

export const healthLabels: Record<HealthState, string> = { ok: 'Healthy', warning: 'Attention', problem: 'Problem', unknown: 'Unknown' };

const runTones: Record<RunState, StatusTone> = { succeeded: 'success', failed: 'danger', running: 'info', unfinished: 'warning' };

const runLabels: Record<RunState, string> = { succeeded: 'Succeeded', failed: 'Failed', running: 'Running', unfinished: 'No finish recorded' };

export function RunStatePill({ state }: { state: RunState | null }) {
    if (state === null) {
        return <StatusPill tone="neutral">No run yet</StatusPill>;
    }

    return <StatusPill tone={runTones[state]}>{runLabels[state]}</StatusPill>;
}

const problemTones: Record<ProblemKind, StatusTone> = { refused: 'danger', unknown: 'warning', conflict: 'info' };

export const problemLabels: Record<ProblemKind, string> = { refused: 'Refused push', unknown: 'Unknown entity', conflict: 'Open conflict' };

export function ProblemKindPill({ kind }: { kind: ProblemKind }) {
    return <StatusPill tone={problemTones[kind]}>{problemLabels[kind]}</StatusPill>;
}

/** "850 ms", "4.2 s", "3 min 05 s", "1 h 02 min". */
export function duration(ms: number | null): string {
    if (ms === null) {
        return '—';
    }
    if (ms < 1000) {
        return `${formatNumber(ms)} ms`;
    }
    const seconds = ms / 1000;
    if (seconds < 60) {
        return `${formatNumber(Math.round(seconds * 10) / 10)} s`;
    }
    const whole = Math.round(seconds);
    if (whole < 3600) {
        return `${Math.floor(whole / 60)} min ${String(whole % 60).padStart(2, '0')} s`;
    }

    return `${Math.floor(whole / 3600)} h ${String(Math.floor((whole % 3600) / 60)).padStart(2, '0')} min`;
}

/** A time as "5 minutes ago" with the full London date and time on hover (and for screen readers). */
export function When({ at, fallback = 'Never', future = false }: { at: string | null; fallback?: string; future?: boolean }) {
    if (!at) {
        return <span className="text-muted-foreground">{fallback}</span>;
    }

    return (
        <time dateTime={at} title={formatDateTime(at)} className="whitespace-nowrap tabular-nums">
            {future ? formatDateTime(at) : ago(at)}
        </time>
    );
}

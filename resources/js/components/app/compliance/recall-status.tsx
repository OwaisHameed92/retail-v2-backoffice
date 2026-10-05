import { StatusBadge } from '@/components/shared/status-badge';

/** A recall's status, read only: closing, reopening and returns are done at a till (ANSWERS-2026-10-06 Q3). */
export function RecallStatus({ status }: { status: 'open' | 'closed' | null }) {
    return (
        <StatusBadge
            status={status ?? 'unknown'}
            label={status === 'open' ? 'Open' : status === 'closed' ? 'Closed' : 'Unknown'}
            tones={{ open: 'danger', closed: 'neutral' }}
        />
    );
}

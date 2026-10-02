import { StatusBadge, type StatusToneMap } from '@/components/shared/status-badge';
import { type AnomalySeverity, type AnomalyStatus } from './types';

export { formatDateTime, formatDay } from '@/components/app/pricing/format';

const SEVERITY_TONES: StatusToneMap = { high: 'danger', medium: 'warning', low: 'info' };
const SEVERITY_LABELS: Record<AnomalySeverity, string> = { high: 'High', medium: 'Medium', low: 'Low' };

/** High (red), medium (amber), low (blue). */
export function SeverityBadge({ severity }: { severity: AnomalySeverity }) {
    return <StatusBadge status={severity} tones={SEVERITY_TONES} label={SEVERITY_LABELS[severity]} />;
}

const STATUS_TONES: StatusToneMap = { new: 'violet', acknowledged: 'info', dismissed: 'neutral' };
const STATUS_LABELS: Record<AnomalyStatus, string> = { new: 'New', acknowledged: 'Acknowledged', dismissed: 'Dismissed' };

/** New (violet), acknowledged (blue), dismissed (grey). */
export function AnomalyStatusBadge({ status }: { status: AnomalyStatus }) {
    return <StatusBadge status={status} tones={STATUS_TONES} label={STATUS_LABELS[status]} />;
}

export const STATUS_FILTERS = [
    { value: 'open', label: 'Open (new or acknowledged)' },
    { value: 'new', label: 'New' },
    { value: 'acknowledged', label: 'Acknowledged' },
    { value: 'dismissed', label: 'Dismissed' },
    { value: 'all', label: 'Every status' },
];

/** Quick reasons offered when dismissing (the user can edit or write their own). */
export const DISMISS_REASONS = [
    'Expected: a known event or busy day',
    'Checked with the staff member: explained',
    'Till or sync problem, now fixed',
    'Opening hours were out of date',
    'Not a problem for this shop',
];

/** "Acknowledged by Sam", "Dismissed by Sam", for the history list. */
export function historyLabel(action: string): string {
    switch (action) {
        case 'anomaly.acknowledged':
            return 'acknowledged it';
        case 'anomaly.dismissed':
            return 'dismissed it';
        case 'anomaly.reopened':
            return 'reopened it';
        default:
            return 'changed it';
    }
}

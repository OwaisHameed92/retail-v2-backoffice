import { type PlanStatusValue } from '@/components/admin/plans/types';
import { StatusBadge, type StatusToneMap } from '@/components/shared/status-badge';

export const planStatusTones: StatusToneMap = {
    active: 'success',
    hidden: 'info',
    inactive: 'neutral',
    archived: 'neutral',
};

/** One line per status, used in tooltips and the filter. */
export const planStatusHelp: Record<PlanStatusValue, string> = {
    active: 'Available for new licences and shown on the pricing page.',
    hidden: 'Available for new licences but not shown on the pricing page.',
    inactive: 'Kept for existing licences. Cannot be chosen for new ones.',
    archived: 'Archived. Restore it to edit or use it again.',
};

export function PlanStatusBadge({ status, label }: { status: PlanStatusValue; label?: string }) {
    return (
        <span title={planStatusHelp[status]}>
            <StatusBadge status={status} label={label} tones={planStatusTones} />
        </span>
    );
}

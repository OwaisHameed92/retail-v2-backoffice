import { StatusBadge, StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { type ConflictKind } from './types';

export { formatDateTimeShort } from '@/components/admin/tenants/format';

export const CONFLICT_INDEX_ONLY = ['tab', 'conflicts', 'clashes', 'filters', 'search', 'counts', 'stats'];

/** Hub rows (the shop's change can still be taken) are amber; historic records and deletes are informational. */
const kindTones: Record<ConflictKind, StatusTone> = {
    hubEditNewer: 'warning',
    hubVersionNewer: 'warning',
    branchEditNewer: 'violet',
    immutableChange: 'info',
    tenancyDelete: 'danger',
};

/** What each kind means, in the owner's words. */
export const kindHelp: Record<ConflictKind, string> = {
    hubEditNewer: 'Someone changed this on the portal after the shop made its change, so the portal kept its own version.',
    hubVersionNewer: 'The portal had a newer version than the one the shop edited, so the portal kept its own version.',
    branchEditNewer: 'Another shop changed this after this shop did, so the newer change from the other shop was kept.',
    immutableChange:
        'The shop tried to change a finished record (a completed sale, a journal or an audit entry). Finished records never change; only what is allowed was applied.',
    tenancyDelete: 'The till deleted its own business, shop or till record. These are managed on the portal and were not deleted.',
};

export function KindPill({ kind, label }: { kind: ConflictKind; label: string }) {
    return (
        <StatusPill tone={kindTones[kind]} className="h-auto min-h-6 py-1 whitespace-normal">
            {label}
        </StatusPill>
    );
}

export function ConflictStatusBadge({ status }: { status: 'open' | 'resolved' }) {
    return <StatusBadge status={status} tone={status === 'open' ? 'warning' : 'success'} label={status === 'open' ? 'Needs review' : 'Resolved'} />;
}

export function ClashBadge({ resolution, label }: { resolution: string; label: string }) {
    const tone: StatusTone = resolution === 'pending' ? 'warning' : resolution === 'ignored' ? 'neutral' : 'success';

    return <StatusBadge status={resolution} tone={tone} label={label} />;
}

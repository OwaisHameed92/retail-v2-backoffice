import { type DeviceFilters, type DeviceKind, type DeviceRow, type DeviceStatus } from '@/components/admin/devices/types';
import { type TableMeta } from '@/components/shared/data-table';
import { StatusBadge, type StatusTone } from '@/components/shared/status-badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { dateLocale, zonedDateFormat } from '@/lib/country';

export const deviceStatusTones: Record<DeviceStatus, StatusTone> = {
    active: 'success',
    expiring: 'warning',
    released: 'info',
    notActivated: 'neutral',
    expired: 'danger',
    suspended: 'danger',
    revoked: 'neutral',
    signedIn: 'success',
    signedOut: 'neutral',
};

/** What each status means, for the badge tooltip and screen readers. */
export const deviceStatusHelp: Record<DeviceStatus, string> = {
    active: 'Licensed and trading.',
    expiring: 'Trading, but the licence ends within a week or is in its grace days.',
    released: 'The key was released from its PC and can be activated on another one.',
    notActivated: 'Key issued, not yet entered on a till.',
    expired: 'The till is locked until the licence is renewed.',
    suspended: 'Locked. The till stops trading at its next check-in.',
    revoked: 'Permanently cancelled. The key never works again.',
    signedIn: 'The handheld app is signed in.',
    signedOut: 'Signed out on the handheld, from the portal, or the user was deactivated.',
};

const dayFormat = () => zonedDateFormat(dateLocale(), { day: 'numeric', month: 'short', year: 'numeric' });

/** "11 Nov 2026" in the shops' time zone. */
export function shopDay(iso: string): string {
    return dayFormat().format(new Date(iso));
}

/** Device status with its meaning, the licence's own status and any lock reason on hover and focus. */
export function DeviceStatusBadge({ row }: { row: DeviceRow }) {
    const lines = [
        row.statusReason ?? deviceStatusHelp[row.status],
        row.licenceStatusLabel && row.licenceStatusLabel !== row.statusLabel ? `Licence: ${row.licenceStatusLabel}.` : null,
        row.endsAt && (row.status === 'active' || row.status === 'expiring') ? `Ends ${shopDay(row.endsAt)}.` : null,
    ].filter(Boolean);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span tabIndex={0} className="focus-visible:ring-ring inline-flex rounded-full focus-visible:ring-2 focus-visible:outline-none">
                    <StatusBadge status={row.status} label={row.statusLabel} tone={deviceStatusTones[row.status]} />
                    <span className="sr-only">. {lines.join(' ')}</span>
                </span>
            </TooltipTrigger>
            <TooltipContent className="max-w-64">{lines.join(' ')}</TooltipContent>
        </Tooltip>
    );
}

/** "Till 2 (02)" or the handheld user's name. */
export function deviceTitle(row: DeviceRow): string {
    return row.kind === 'till' && row.code ? `${row.name} (${row.code})` : row.name;
}

/** "Leeds (LDS)" */
export function shopLabel(row: DeviceRow): string {
    return row.branch.code ? `${row.branch.name} (${row.branch.code})` : row.branch.name;
}

/** The page's query string for the current filters, with changes; empty values dropped. */
export function devicesQuery(
    filters: DeviceFilters,
    meta: TableMeta,
    changes: Record<string, string | number | null> = {},
): Record<string, string | number> {
    const base: Record<string, string | number | null> = {
        search: meta.search ?? null,
        kind: filters.kind,
        status: filters.status,
        old: filters.old ? 1 : null,
        below: filters.below,
        unseen: filters.unseen ? 1 : null,
        days: filters.unseen && filters.days !== 7 ? filters.days : null,
        sort: meta.sort ?? null,
        direction: meta.sort ? (meta.direction ?? null) : null,
        ...changes,
    };

    return Object.fromEntries(Object.entries(base).filter((entry): entry is [string, string | number] => entry[1] !== null && entry[1] !== ''));
}

/** The kind a status belongs to, so switching kind can drop a status of the other kind. */
export function statusFitsKind(status: DeviceStatus | null, kind: DeviceKind | null, statuses: { value: DeviceStatus; kind: DeviceKind }[]): boolean {
    return status === null || kind === null || statuses.find((option) => option.value === status)?.kind === kind;
}

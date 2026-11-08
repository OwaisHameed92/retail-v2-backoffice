/** Admin Devices page. Matches App\Domain\Devices\Data\DevicePresenter, DeviceSummary and DeviceController. */
import { type LicenceStatus } from '@/components/admin/licences/types';
import { type Paginated } from '@/components/shared/data-table';

export type DeviceKind = 'till' | 'handheld';

export type DeviceStatus = 'active' | 'expiring' | 'released' | 'notActivated' | 'expired' | 'suspended' | 'revoked' | 'signedIn' | 'signedOut';

/** One device. Dates are ISO-8601 UTC. Till-only fields are null for a handheld. */
export interface DeviceRow {
    id: string;
    kind: DeviceKind;
    company: { id: string; name: string };
    branch: { id: string; name: string; code: string | null };
    /** Till name or handheld user name. */
    name: string;
    /** Till number. */
    code: string | null;
    isMainTill: boolean | null;
    /** EPOS version on the last licence call (handhelds send none). */
    appVersion: string | null;
    /** Older than the version the page compares with. */
    appOutdated: boolean;
    lastSeenAt: string | null;
    status: DeviceStatus;
    statusLabel: string;
    licenceStatus: LicenceStatus | null;
    licenceStatusLabel: string | null;
    /** Why the till is locked, e.g. "The branch is deactivated." */
    statusReason: string | null;
    endsAt: string | null;
    licenceId: string | null;
    keyLast4: string | null;
    installId: string | null;
    deviceName: string | null;
    clockSkewSeconds: number | null;
    /** A PC is bound and the licence is not revoked (the action still needs `can.release`). */
    canRelease: boolean;
}

export interface DeviceSummary {
    tills: number;
    liveToday: number;
    notSeen: number;
    notSeenDays: number;
    oldVersion: number;
    gate: string | null;
    handhelds: number;
}

export interface DeviceFilters {
    kind: DeviceKind | null;
    status: DeviceStatus | null;
    old: boolean;
    /** The version typed by the admin; null = the highest seen (`gate`). */
    below: string | null;
    unseen: boolean;
    days: number;
    /** The version "old" compares with: `below`, else the highest seen. */
    gate: string | null;
}

export interface DeviceStatusOption {
    value: DeviceStatus;
    label: string;
    kind: DeviceKind;
}

export interface DevicesIndexProps {
    devices: Paginated<DeviceRow>;
    summary: DeviceSummary;
    filters: DeviceFilters;
    kinds: { value: DeviceKind; label: string }[];
    statuses: DeviceStatusOption[];
    versions: { version: string; tills: number }[];
    /** Till health's clock skew limit in seconds. */
    clockSkewSeconds: number;
    can: { release: boolean };
}

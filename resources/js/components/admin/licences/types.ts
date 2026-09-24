import { type Paginated } from '@/components/shared/data-table';

export type LicenceStatus = 'issued' | 'trial' | 'active' | 'grace' | 'expired' | 'suspended' | 'revoked';

export interface Option<T extends string = string> {
    value: T;
    label: string;
}

export interface PlanOption extends Option {
    description: string | null;
}

export interface LicencePlanRef {
    id: string;
    name: string;
    code: string;
    archived: boolean;
}

/** LicenceData::row (list rows, tenant tab). Dates are ISO-8601 UTC. */
export interface LicenceRow {
    id: string;
    maskedKey: string;
    keyLast4: string;
    /** Effective status: what the till is told (LicenceState). */
    status: LicenceStatus;
    statusLabel: string;
    statusReason: string | null;
    storedStatus: LicenceStatus;
    isTrial: boolean;
    company: { id: string; name: string };
    branch: { id: string; name: string; code: string | null };
    register: { id: string; name: string; code: string | null; isMainTill: boolean };
    plan: LicencePlanRef | null;
    activatedAt: string | null;
    trialEndsAt: string | null;
    expiresAt: string | null;
    endsAt: string | null;
    graceEndsAt: string | null;
    deviceId: string | null;
    deviceName: string | null;
    boundAt: string | null;
    lastCheckInAt: string | null;
    lastAppVersion: string | null;
    createdAt: string | null;
}

/** LicenceData::detail. */
export interface LicenceDetail extends LicenceRow {
    features: Option[];
    graceDays: number;
    lastIp: string | null;
    suspendedAt: string | null;
    suspendedReason: string | null;
    revokedAt: string | null;
    revokedReason: string | null;
    notes: string | null;
    isRevoked: boolean;
    isSuspended: boolean;
    isBound: boolean;
    updatedAt: string | null;
}

export interface TimelineEvent {
    key: string;
    label: string;
    detail: string | null;
    at: string;
    upcoming: boolean;
    tone: 'neutral' | 'success' | 'info' | 'warning' | 'danger';
}

export type LicenceAlertType = 'sameKeyTwoDevices' | 'deviceMismatch' | 'reissuedKeyUsed';

/** LicenceAlertData::forLicence: alerts raised by the till API (module 1.5). No keys, only device id endings. */
export interface LicenceAlert {
    id: string;
    type: LicenceAlertType;
    label: string;
    help: string;
    details: {
        deviceName: string | null;
        deviceIdEnding: string | null;
        ip: string | null;
        appVersion: string | null;
        os: string | null;
        boundDeviceName: string | null;
        boundDeviceIdEnding: string | null;
        retiredKeyLast4: string | null;
        attempted: string | null;
    };
    count: number;
    firstSeenAt: string | null;
    lastSeenAt: string | null;
    resolvedAt: string | null;
    resolvedBy: string | null;
}

export interface LicenceActivityEntry {
    id: string;
    action: string;
    description: string;
    actorName: string;
    createdAt: string | null;
}

/** A plain key from the reply that created it (LicenceData::issuedKey). Shown once, never stored. */
export interface IssuedKey {
    licenceId: string;
    key: string;
    keyLast4: string;
    companyId: string;
    businessName: string | null;
    branchName: string | null;
    tillName: string | null;
    tillCode: string | null;
    replacedKey: boolean;
}

export interface IssuedKeysReply {
    message: string;
    keys: IssuedKey[];
}

export interface TillLicence {
    id: string;
    status: LicenceStatus;
    statusLabel: string;
    maskedKey: string;
    keyLast4: string;
    deviceName: string | null;
}

/** TenantLicences::for, on the tenant page. */
export interface TenantLicensing {
    licences: LicenceRow[];
    tillLicences: Record<string, TillLicence>;
    summary: {
        counts: Record<LicenceStatus, number>;
        live: number;
        missing: number;
        renewable: number;
        latestEnd: string | null;
        /** Licence API alerts not yet resolved (module 1.5). */
        openAlerts: number;
    };
    plan: { current: LicencePlanRef | null; isCompanyPlan: boolean };
}

export interface LicenceIndexProps {
    licences: Paginated<LicenceRow>;
    filters: { status: LicenceStatus | null; plan: string | null; company: { id: string; name: string } | null };
    statuses: Option<LicenceStatus>[];
    counts: Record<LicenceStatus, number>;
    total: number;
    plans: Option[];
}

export interface LicenceShowProps {
    licence: LicenceDetail;
    timeline: TimelineEvent[];
    activity: LicenceActivityEntry[];
    alerts: LicenceAlert[];
    plans: PlanOption[];
    can: { manage: boolean };
}

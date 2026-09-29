/** Module 2.7 (Till health). Matches App\Domain\TillHealth\Data\HealthPresenter and the queries that use it. */

export type TillState = 'online' | 'stale' | 'offline' | 'notActivated';
export type SyncState = 'notLinked' | 'healthy' | 'failing' | 'stalled';
export type HealthProblemValue = 'offline' | 'oldVersion' | 'syncFailing' | 'syncStalled' | 'clockSkew';

export interface HealthProblem {
    value: HealthProblemValue;
    label: string;
}

/** HealthPresenter::till(). `installId` is null in the tenant portal. */
export interface TillHealth {
    registerId: string;
    branchId: string;
    licenceId: string | null;
    state: TillState;
    stateLabel: string;
    isSyncTill: boolean;
    syncState: SyncState;
    syncStateLabel: string;
    lastSeenAt: string | null;
    lastValidatedAt: string | null;
    lastPushAt: string | null;
    lastPullAt: string | null;
    appVersion: string | null;
    appOutdated: boolean;
    contractVersion: string | null;
    installId: string | null;
    deviceName: string | null;
    clockSkewSeconds: number | null;
    clockSkewed: boolean;
    pendingSyncRows: number | null;
    lock: { locked: boolean; reason: string | null } | null;
    problems: HealthProblem[];
}

/** HealthPresenter::branch(). */
export interface ShopHealth {
    branchId: string;
    state: TillState;
    stateLabel: string;
    syncState: SyncState;
    syncStateLabel: string;
    lastContactAt: string | null;
    lastSyncAt: string | null;
    lastPushAt: string | null;
    lastPullAt: string | null;
    lastError: { code: string | null; message: string | null; at: string | null } | null;
    tills: number;
    tillsOnline: number;
    tillsOffline: number;
}

/** HealthThresholds::toArray(). */
export interface HealthThresholds {
    syncOnlineMinutes: number;
    syncOfflineHours: number;
    validateOnlineHours: number;
    validateOfflineHours: number;
    clockSkewSeconds: number;
    syncFailingHours: number;
    syncStalledHours: number;
    alertOfflineHours: number;
    minimumAppVersion: string;
    tradingStart: string;
    tradingEnd: string;
    timezone: string;
    refreshMinutes: number;
}

/** TillHealthSummary::compute(). */
export interface TillHealthSummary {
    tills: number;
    online: number;
    stale: number;
    offline: number;
    notActivated: number;
    attention: number;
    oldVersion: number;
    sync: number;
    clockSkew: number;
    shops: number;
    shopsOnline: number;
    shopsSyncing: number;
    checkedAt: string | null;
}

/** TillHealthList::paginate() rows. */
export interface TillHealthListRow extends TillHealth {
    id: string;
    company: { id: string; name: string };
    branch: { id: string; name: string; code: string };
    register: { name: string; code: string; isMainTill: boolean };
    checkedAt: string;
}

export type TillHealthFilter = 'attention' | 'offline' | 'oldVersion' | 'sync' | 'clockSkew';

/** ShopsStatus::for(): the tenant dashboard's "Shops and tills". */
export interface ShopsStatus {
    shops: {
        id: string;
        name: string;
        code: string;
        health: ShopHealth | null;
        tills: { id: string; name: string; code: string; isMainTill: boolean; health: TillHealth | null }[];
    }[];
    thresholds: HealthThresholds;
}

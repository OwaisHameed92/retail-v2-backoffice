/** Module 2.8: a shop's move to the cloud (cloud/migrate + initial upload), as CloudLinkPresenter::upload sends it. */
export interface CloudMove {
    id: string;
    company: { id: string; name: string | null };
    branch: { id: string; name: string | null; code: string | null };
    status: 'open' | 'complete';
    statusLabel: string;
    deviceName: string | null;
    installCode: string | null;
    appVersion: string | null;
    expectedRows: number;
    receivedRows: number;
    percent: number;
    acknowledgedSeq: number;
    missing: { entity: string; expected: number; received: number }[];
    completeCalls: number;
    carriedOverDays: number;
    localLicenceId: string | null;
    licenceId: string | null;
    companyIdAction: 'adopted' | 'aliased' | null;
    branchIdAction: 'adopted' | 'aliased' | null;
    firstSaleAt: string | null;
    lastSaleAt: string | null;
    startedAt: string | null;
    lastBatchAt: string | null;
    completedAt: string | null;
}

/** Module 2.8: one record of the local key register (licence/redeem reports), CloudLinkPresenter::localKey. */
export interface LocalKeyRecord {
    id: string;
    licenceId: string;
    installCode: string;
    installIdEnding: string | null;
    deviceName: string | null;
    appVersion: string | null;
    businessName: string | null;
    branchName: string | null;
    company: { id: string; name: string | null } | null;
    kind: string | null;
    issuer: string | null;
    kid: string;
    tokenHashStart: string;
    features: string[];
    maxRegisters: number | null;
    installsForShop: number | null;
    expiresAt: string | null;
    expired: boolean;
    reportedVia: 'report' | 'redeem' | 'migrate';
    firstSeenAt: string | null;
    lastReportedAt: string | null;
    reportCount: number;
    refusedCount: number;
    lastRefusedAt: string | null;
}

export interface CloudLinkSummary {
    uploading: number;
    complete: number;
    localKeys: number;
    refused: number;
}

export const reportedViaLabels: Record<LocalKeyRecord['reportedVia'], string> = {
    report: 'Reported by the till',
    redeem: 'Entered at a linked till',
    migrate: 'Moved to the cloud',
};

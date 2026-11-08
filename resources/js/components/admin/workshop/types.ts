/** W5 BikeBook connector: matches `WorkshopCard::forBranch()` and `WorkshopLog` rows. */

export type WorkshopStatus = 'notConnected' | 'pending' | 'connected' | 'failed' | 'disconnected';

export interface WorkshopCounts {
    productsInScope: number;
    customers: number;
    openOrders: number;
    failedWebhooks: number;
}

export interface WorkshopBranchCard {
    branchId: string;
    branchName: string;
    branchActive: boolean;
    addOn: boolean;
    addOnLabel: string;
    connectionId: string | null;
    status: WorkshopStatus;
    statusLabel: string;
    connectedAt: string | null;
    disconnectedAt: string | null;
    lastCallAt: string | null;
    lastError: string | null;
    apiKey: { masked: string | null; createdAt: string | null } | null;
    oauth: boolean;
    webhook: { url: string; subscriptions: string[]; enabled: boolean } | null;
    counts: WorkshopCounts | null;
}

export interface WorkshopTenantData {
    branches: WorkshopBranchCard[];
    canManage: boolean;
}

export interface WorkshopCallRow {
    id: number;
    createdAt: string;
    method: string;
    path: string;
    status: number;
    durationMs: number;
    auth: string | null;
    requestId: string | null;
    error: string | null;
}

export interface WorkshopDeliveryRow {
    id: string;
    createdAt: string;
    event: string;
    orderId: string | null;
    state: 'queued' | 'retrying' | 'delivered' | 'failed';
    attempts: number;
    lastStatus: number | null;
    lastError: string | null;
    nextAttemptAt: string | null;
    deliveredAt: string | null;
    failedAt: string | null;
}

export const WORKSHOP_STATUS_TONES = {
    notConnected: 'neutral',
    pending: 'warning',
    connected: 'success',
    failed: 'danger',
    disconnected: 'neutral',
} as const;

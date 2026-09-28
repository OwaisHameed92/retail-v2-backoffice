export type PlanStatusValue = 'active' | 'hidden' | 'inactive' | 'archived';

/** A row from App\Domain\Plans\Data\PlanData::row(). Money is a 2 dp string in pounds. */
export interface PlanRow {
    id: string;
    name: string;
    code: string;
    pricePerTillMonthly: string;
    pricePerTillYearly: string;
    currency: string;
    trialDays: number;
    featureCount: number;
    status: PlanStatusValue;
    statusLabel: string;
    sortOrder: number;
}

/** App\Domain\Plans\Data\PlanData::fromModel(). */
export interface PlanRecord {
    id: string;
    name: string;
    code: string;
    description: string | null;
    pricePerTillMonthly: string;
    pricePerTillYearly: string;
    setupFee: string;
    currency: string;
    trialDays: number;
    trialGraceDays: number;
    graceDays: number;
    yearlySaving: string;
    yearlySavingPercent: number | null;
    features: string[];
    isActive: boolean;
    isPublic: boolean;
    sortOrder: number;
    status: PlanStatusValue;
    statusLabel: string;
    isInUse: boolean;
    createdAt: string | null;
    updatedAt: string | null;
    archivedAt: string | null;
}

export interface FeatureOption {
    value: string;
    label: string;
    description: string;
}

export interface PlanActivityEntry {
    id: string;
    action: string;
    summary: string;
    actorName: string;
    changes: { label: string; from: string | null; to: string }[];
    createdAt: string | null;
}

export interface PlanToast {
    id: string;
    type: 'success' | 'error';
    message: string;
}

export type PlanStatusValue = 'active' | 'hidden' | 'inactive' | 'archived';

/** Owner rules 2026-10-05: how a plan is paid for. */
export type PlanBillingType = 'setupOnly' | 'setupAndRecurring' | 'recurringOnly';

/** A row from App\Domain\Plans\Data\PlanData::row(). Money is a 2 dp string in pounds. */
export interface PlanRow {
    id: string;
    name: string;
    code: string;
    pricingMode: 'perTill' | 'perBranch';
    billingType: PlanBillingType;
    billingTypeLabel: string;
    priceMonthly: string;
    priceYearly: string;
    currency: string;
    trialDays: number;
    featureCount: number;
    /** Owner 2026-10-21: the handheld (PDA) app comes with the plan (portal only, not a till feature). */
    includesHandheld: boolean;
    /** OS-7: the online store add-on comes with the plan (false where the country has no online stores). */
    includesOnlineStore: boolean;
    /** OS-13: online store only (no tills), with the store fee (its whole monthly fee). */
    onlineStoreOnly: boolean;
    onlineStorePrice: string | null;
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
    pricingMode: 'perTill' | 'perBranch';
    billingType: PlanBillingType;
    billingTypeLabel: string;
    priceMonthly: string;
    priceYearly: string;
    setupFee: string;
    /** P11: the setup fee is charged once per business or for each till (also tills added later). */
    setupFeeMode: 'perBusiness' | 'perTill';
    setupFeeModeLabel: string;
    currency: string;
    trialDays: number;
    trialGraceDays: number;
    graceDays: number;
    yearlySaving: string;
    yearlySavingPercent: number | null;
    features: string[];
    includesHandheld: boolean;
    /** OS-7: as stored (the form's values); `onlineStore` is the add-on's price when the plan includes it here. */
    includesOnlineStore: boolean;
    onlineStoreMonthlyPrice: string | null;
    onlineStore: { price: string; priceLabel: string } | null;
    /** OS-13: as stored. */
    onlineStoreOnly: boolean;
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

/** OS-7: the plan pages' online store settings (PlanController); null where the country has no online stores. */
export interface PlanOnlineStoreSettings {
    /** Decimal string ("20.00"): the price when the plan's own is blank. */
    defaultPrice: string;
    /** Online stores are switched on on this server (ONLINE_STORE). */
    serverOn: boolean;
}

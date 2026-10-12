import { type OnboardingBillingOptions } from '@/components/admin/billing/upfront-payment-fields';
import { type LicenceOptions, type PlanDefaults, type PlanOption } from '@/components/admin/licences/types';
import { type BranchFieldsData } from '@/components/admin/tenants/branch-fields';
import { type CompanyFieldsData } from '@/components/admin/tenants/company-fields';
import { type Nation, type Option } from '@/components/admin/tenants/types';
import { type CompanyStatus } from '@/types';

export type PartnerRequestStatus = 'pendingReview' | 'approved' | 'rejected' | 'cancelled';

/** PartnerRequestList::row(). */
export interface PartnerRequestRow {
    id: string;
    externalId: string;
    businessName: string;
    partner: string | null;
    sellerName: string;
    sellerEmail: string;
    status: PartnerRequestStatus;
    partnerCancelled: boolean;
    tills: number;
    shops: number;
    plan: string | null;
    company: { id: string; name: string } | null;
    createdAt: string | null;
    updatedAt: string | null;
}

/** The partner's body as stored (docs/partner-api.md), camelCase. */
export interface PartnerPayload {
    seller: { name: string; email: string };
    business: {
        name: string;
        legalName: string | null;
        companyNumber: string | null;
        vatNumber: string | null;
        phone: string | null;
        email: string | null;
    };
    owner: { name: string; email: string; phone: string | null };
    shops: { name: string; address: string | null; town: string | null; postcode: string | null; tills: number }[];
    plan: string;
    setupFeePerTill: string | null;
    vatApplies: boolean;
    salePrice: string | null;
    signedOn: string | null;
    notes: string | null;
}

/** PartnerRequestDetail::for()['request']. */
export interface PartnerRequestDetailData extends Omit<PartnerRequestRow, 'company'> {
    payload: PartnerPayload;
    approvedAt: string | null;
    approvedBy: string | null;
    rejectedAt: string | null;
    rejectedBy: string | null;
    rejectedReason: string | null;
    cancelledAt: string | null;
    cancelledReason: string | null;
    partnerCancelledAt: string | null;
    company: { id: string; name: string; status: CompanyStatus; deleted: boolean } | null;
}

export interface PartnerWebhookRow {
    id: string;
    type: string;
    label: string;
    attempts: number;
    lastStatus: number | null;
    lastError: string | null;
    deliveredAt: string | null;
    failedAt: string | null;
    nextAttemptAt: string | null;
    createdAt: string | null;
}

export interface PartnerActivityRow {
    id: string;
    action: string;
    actor: string | null;
    at: string | null;
    reason: string | null;
}

/** One shop of the review form: the wizard's branch fields plus its tills. */
export type ReviewShop = BranchFieldsData & { tills: number; tills_allowed: number };

/** ReviewFormDefaults::for() (+ the licence form, filled in on the page). */
export type ReviewFormValues = CompanyFieldsData & {
    status: 'trial' | 'active';
    trial_ends_at: string;
    notes: string;
    owner_name: string;
    owner_email: string;
    plan_id: string;
    setup_fee_per_till: string;
    vat_applies: boolean;
    shops: ReviewShop[];
};

export interface ReviewOptions {
    nations: Option<Nation>[];
    maxTills: number;
    maxShops: number;
    plans: PlanOption[];
    licenceOptions: LicenceOptions;
    planDefaults: PlanDefaults;
    billing: OnboardingBillingOptions;
}

/** PartnerSettingsData::all(). */
export interface PartnerKeyRow {
    id: string;
    name: string | null;
    masked: string;
    createdAt: string | null;
    lastUsedAt: string | null;
    revokedAt: string | null;
}

export interface PartnerSettingsRow {
    id: string;
    name: string;
    webhookUrl: string | null;
    hasSecret: boolean;
    secretEnding: string | null;
    isActive: boolean;
    requests: number;
    pendingRequests: number;
    createdAt: string | null;
    keys: PartnerKeyRow[];
}
